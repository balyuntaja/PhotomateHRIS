<?php

namespace App\Services;

use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Karyawan;
use App\Models\SessionReport;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionAccountingService
{
    public const REF_FINANCIAL_TRANSACTION = 'financial_transaction';

    public const REF_SESSION_REPORT = 'session_report';

    public const REF_INVOICE = 'invoice';

    public const REF_LUMINASH = 'luminash_csv';

    public function __construct(
        protected JournalPostingService $postingService
    ) {}

    /**
     * Cari akun COA berdasarkan kode akun.
     */
    public function accountByCode(string $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('code', $code)->first();

        if (! $account) {
            throw new DomainException("Akun COA dengan kode {$code} tidak ditemukan pada Chart of Accounts.");
        }

        return $account;
    }

    /**
     * Rencana jurnal transaksi operasional tanpa menyimpan apa pun.
     * Dipakai proses posting dan mode dry-run backfill.
     *
     * @return array{date: string|null, lines: array<int, array<string, mixed>>, error: string|null, period_closed: bool}
     */
    public function planFinancialTransaction(FinancialTransaction $transaction): array
    {
        if ((int) $transaction->amount <= 0) {
            return [
                'date' => null,
                'lines' => [],
                'error' => 'Nominal transaksi harus lebih dari nol.',
                'period_closed' => false,
            ];
        }

        $category = FinancialCategory::query()->find($transaction->category_id);
        $mapping = CategoryAccountMapping::resolveForCategory($category);

        if (! $mapping) {
            return [
                'date' => null,
                'lines' => [],
                'error' => "Mapping kategori '".($category?->name ?? '-')."' belum tersedia atau tidak aktif.",
                'period_closed' => false,
            ];
        }

        $financialAccount = FinancialAccount::query()->find($transaction->account_id);
        $cashCoaId = $financialAccount?->chart_of_account_id;

        try {
            $payableAccountId = $this->accountByCode('2000')->getKey();
        } catch (DomainException $exception) {
            return ['date' => null, 'lines' => [], 'error' => $exception->getMessage(), 'period_closed' => false];
        }

        $isIncome = $transaction->transaction_type === FinancialTransaction::TYPE_INCOME;
        $isDebtPayment = ! $isIncome
            && (int) $mapping->debit_account_id === (int) $payableAccountId;

        $needsCash = $isIncome
            ? $transaction->payment_status === FinancialTransaction::PAYMENT_STATUS_LUNAS
            : ($isDebtPayment || $transaction->payment_status !== FinancialTransaction::PAYMENT_STATUS_UTANG);

        if ($needsCash && $cashCoaId === null) {
            return [
                'date' => null,
                'lines' => [],
                'error' => "Akun operasional '".($financialAccount?->name ?? '-')."' belum terhubung ke Chart of Accounts.",
                'period_closed' => false,
            ];
        }

        $amount = (int) $transaction->amount;
        $description = $transaction->description;
        $lines = [];

        if ($isIncome) {
            if ($transaction->payment_status === FinancialTransaction::PAYMENT_STATUS_LUNAS) {
                $lines = [
                    ['chart_of_account_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                    ['chart_of_account_id' => $mapping->credit_account_id, 'debit' => 0, 'credit' => $amount, 'description' => $description],
                ];
            } else {
                try {
                    $receivableAccountId = $this->accountByCode('1100')->getKey();
                } catch (DomainException $exception) {
                    return ['date' => null, 'lines' => [], 'error' => $exception->getMessage(), 'period_closed' => false];
                }

                $lines = [
                    ['chart_of_account_id' => $receivableAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                    ['chart_of_account_id' => $mapping->credit_account_id, 'debit' => 0, 'credit' => $amount, 'description' => $description],
                ];
            }
        } elseif ($isDebtPayment) {
            $lines = [
                ['chart_of_account_id' => $payableAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                ['chart_of_account_id' => $cashCoaId, 'debit' => 0, 'credit' => $amount, 'description' => $description],
            ];
        } elseif ($transaction->payment_status === FinancialTransaction::PAYMENT_STATUS_UTANG) {
            $lines = [
                ['chart_of_account_id' => $mapping->debit_account_id, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                ['chart_of_account_id' => $payableAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $description],
            ];
        } else {
            $lines = [
                ['chart_of_account_id' => $mapping->debit_account_id, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                ['chart_of_account_id' => $cashCoaId, 'debit' => 0, 'credit' => $amount, 'description' => $description],
            ];
        }

        $date = $transaction->transaction_date?->toDateString() ?? now()->toDateString();
        $period = FiscalPeriod::forDate($date);

        if ($period && $period->isClosed()) {
            return [
                'date' => $date,
                'lines' => $lines,
                'error' => 'Periode akuntansi '.$period->label.' sudah ditutup.',
                'period_closed' => true,
            ];
        }

        return ['date' => $date, 'lines' => $lines, 'error' => null, 'period_closed' => false];
    }

    /**
     * Sinkronkan transaksi keuangan operasional (manual atau umum) ke jurnal akuntansi.
     * Idempotent: nilai yang sama tidak membuat jurnal baru, perubahan nilai membalik
     * jurnal aktif lalu memposting versi baru.
     */
    public function syncFinancialTransaction(
        FinancialTransaction $transaction,
        ?Karyawan $actor = null,
        bool $failOnError = false
    ): ?JournalEntry {
        if ($transaction->trashed()) {
            return $this->reverseFinancialTransaction(
                $transaction,
                "Pembatalan transaksi operasional ID #{$transaction->id}",
                $actor
            );
        }

        if ($this->isManagedByAnotherSource($transaction)) {
            return null;
        }

        try {
            return $this->transaction(function () use ($transaction, $actor): ?JournalEntry {
                $baseKey = 'ft:'.$transaction->getKey();
                $current = $this->currentJournal(
                    self::REF_FINANCIAL_TRANSACTION,
                    (string) $transaction->getKey(),
                    $baseKey
                );

                $plan = $this->planFinancialTransaction($transaction);
                $date = $plan['date'];
                $lines = $plan['lines'];
                $closedPeriodCorrection = false;

                if ($plan['error'] !== null || $lines === []) {
                    if ($current && $plan['period_closed'] && $lines !== []) {
                        // Koreksi transaksi yang periode asalnya sudah ditutup:
                        // reversal dan versi baru diposting pada periode berjalan yang terbuka.
                        $closedPeriodCorrection = true;
                        $date = $this->resolveReversalDate($current->journal_date);
                    } else {
                        if ($current) {
                            $this->reverseJournal(
                                $current,
                                "Pembatalan jurnal transaksi #{$transaction->id}: {$plan['error']}",
                                $actor
                            );
                        }

                        Log::warning("Transaksi #{$transaction->id} belum diposting ke jurnal: {$plan['error']}");

                        return null;
                    }
                }

                if ($current && $this->journalMatches($current, $date, $lines)) {
                    return $current;
                }

                if ($current) {
                    $this->reverseJournal(
                        $current,
                        $closedPeriodCorrection
                            ? "Koreksi transaksi operasional ID #{$transaction->id} (periode asal sudah ditutup)"
                            : "Koreksi transaksi operasional ID #{$transaction->id}",
                        $actor
                    );
                }

                $idempotencyKey = $this->versionedKey(
                    $baseKey,
                    self::REF_FINANCIAL_TRANSACTION,
                    (string) $transaction->getKey()
                );

                $description = $transaction->description ?: "Transaksi {$transaction->type_label} #{$transaction->id}";

                return $this->postingService->postJournal([
                    'journal_date' => $date,
                    'description' => $closedPeriodCorrection
                        ? $description.' (koreksi periode tertutup)'
                        : $description,
                    'reference_type' => self::REF_FINANCIAL_TRANSACTION,
                    'reference_id' => (string) $transaction->id,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actor?->karyawan_id,
                ], $lines, $actor);
            });
        } catch (\Throwable $exception) {
            if ($failOnError) {
                throw $exception;
            }

            Log::warning("Gagal memposting jurnal transaksi #{$transaction->id}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * Reverse jurnal aktif milik transaksi operasional yang dihapus atau dibatalkan.
     */
    public function reverseFinancialTransaction(
        FinancialTransaction $transaction,
        ?string $reason = null,
        ?Karyawan $actor = null
    ): ?JournalEntry {
        if ($this->isManagedByAnotherSource($transaction)) {
            return null;
        }

        $current = $this->currentJournal(
            self::REF_FINANCIAL_TRANSACTION,
            (string) $transaction->getKey(),
            'ft:'.$transaction->getKey()
        );

        if (! $current) {
            return null;
        }

        return $this->reverseJournal(
            $current,
            $reason ?: "Pembatalan transaksi operasional #{$transaction->id}",
            $actor
        );
    }

    /**
     * Sinkronkan rekap sesi booth yang telah disetujui (APPROVED) ke jurnal umum.
     *
     * @return array<int, JournalEntry>
     */
    public function syncSessionReport(SessionReport $report, ?Karyawan $actor = null): array
    {
        if ($report->status !== SessionReport::STATUS_APPROVED) {
            return $this->reverseSessionReport(
                $report,
                "Rekap sesi #{$report->report_number} tidak lagi berstatus approved",
                $actor
            );
        }

        // Pendapatan yang sudah diakui melalui invoice tidak diakui ulang di sini.
        if (filled($report->invoice_id)) {
            return [];
        }

        return $this->transaction(function () use ($report, $actor): array {
            $revenueCoaId = $this->accountByCode('4000')->getKey();
            $expected = app(SessionReportFinancialService::class)->expectedSources($report);

            if ($expected !== []) {
                $hasFinancialTransaction = FinancialTransaction::query()
                    ->where('source_type', SessionReportFinancialService::SOURCE)
                    ->whereIn('source_id', array_keys($expected))
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $hasFinancialTransaction) {
                    try {
                        app(SessionReportFinancialService::class)->record($report, $actor);
                    } catch (\Throwable $exception) {
                        Log::info("Pencatatan keuangan rekap sesi #{$report->id} dilewati: {$exception->getMessage()}");
                    }
                }
            }

            $expectedBaseKeys = [];

            foreach (array_keys($expected) as $sourceId) {
                $expectedBaseKeys[] = 'session_report:'.$report->id.':'.$sourceId;
            }

            foreach ($this->currentJournals(self::REF_SESSION_REPORT, (string) $report->id) as $journal) {
                if (! $this->belongsToAnyBaseKey((string) $journal->idempotency_key, $expectedBaseKeys)) {
                    $this->reverseJournal(
                        $journal,
                        "Pembatalan pendapatan rekap sesi #{$report->report_number} yang tidak lagi berlaku",
                        $actor
                    );
                }
            }

            $postedJournals = [];

            foreach ($expected as $sourceId => $expectedAmount) {
                $baseKey = 'session_report:'.$report->id.':'.$sourceId;
                $current = $this->currentJournal(self::REF_SESSION_REPORT, (string) $report->id, $baseKey);

                $transaction = FinancialTransaction::query()
                    ->where('source_type', SessionReportFinancialService::SOURCE)
                    ->where('source_id', $sourceId)
                    ->whereNull('deleted_at')
                    ->first();

                if (! $transaction || (int) $transaction->amount <= 0) {
                    if ($current) {
                        $this->reverseJournal(
                            $current,
                            "Pembatalan pendapatan rekap sesi #{$report->report_number}",
                            $actor
                        );
                    }

                    continue;
                }

                $cashCoaId = FinancialAccount::query()
                    ->find($transaction->account_id)
                    ?->chart_of_account_id;

                if ($cashCoaId === null) {
                    if ($current) {
                        $this->reverseJournal(
                            $current,
                            "Pembatalan jurnal rekap sesi #{$report->report_number}: akun kas tidak valid",
                            $actor
                        );
                    }

                    Log::warning("Rekap sesi #{$report->id} belum diposting: akun kas belum memiliki tautan COA.");

                    continue;
                }

                $date = $transaction->transaction_date?->toDateString()
                    ?? $report->report_date?->toDateString()
                    ?? now()->toDateString();

                $lines = [
                    ['chart_of_account_id' => $cashCoaId, 'debit' => (int) $transaction->amount, 'credit' => 0, 'description' => $transaction->description],
                    ['chart_of_account_id' => $revenueCoaId, 'debit' => 0, 'credit' => (int) $transaction->amount, 'description' => $transaction->description],
                ];

                if ($current && $this->journalMatches($current, $date, $lines)) {
                    $postedJournals[] = $current;

                    continue;
                }

                if ($current) {
                    $this->reverseJournal(
                        $current,
                        "Koreksi pendapatan rekap sesi #{$report->report_number}",
                        $actor
                    );
                }

                $postedJournals[] = $this->postingService->postJournal([
                    'journal_date' => $date,
                    'description' => $transaction->description ?: "Pendapatan rekap sesi #{$report->report_number}",
                    'reference_type' => self::REF_SESSION_REPORT,
                    'reference_id' => (string) $report->id,
                    'idempotency_key' => $this->versionedKey(
                        $baseKey,
                        self::REF_SESSION_REPORT,
                        (string) $report->id
                    ),
                    'created_by' => $actor?->karyawan_id,
                ], $lines, $actor);
            }

            return $postedJournals;
        });
    }

    /**
     * Reverse seluruh jurnal aktif milik rekap sesi yang dibatalkan, ditolak, atau dihapus.
     *
     * @return array<int, JournalEntry>
     */
    public function reverseSessionReport(
        SessionReport $report,
        ?string $reason = null,
        ?Karyawan $actor = null
    ): array {
        return $this->transaction(function () use ($report, $reason, $actor): array {
            $reversals = [];

            foreach ($this->currentJournals(self::REF_SESSION_REPORT, (string) $report->id) as $journal) {
                $reversals[] = $this->reverseJournal(
                    $journal,
                    $reason ?: "Pembatalan rekap sesi #{$report->report_number}",
                    $actor
                );
            }

            // Jika rekap ini tertaut ke invoice, pendapatan yang tadinya diakui
            // rekap harus dihitung ulang oleh invoice agar tidak hilang.
            if (filled($report->invoice_id)) {
                $invoice = Invoice::query()->find($report->invoice_id);

                if ($invoice) {
                    $this->syncInvoice($invoice, $actor);
                }
            }

            return $reversals;
        });
    }

    /**
     * Sinkronkan siklus akuntansi invoice (pengakuan piutang, DP, pelunasan).
     *
     * @return array<int, JournalEntry>
     */
    public function syncInvoice(Invoice $invoice, ?Karyawan $actor = null): array
    {
        if ($invoice->status === 'Dibatalkan') {
            return $this->reverseInvoice($invoice, 'Invoice dibatalkan', $actor);
        }

        // Invoice draft belum mengakui pendapatan; jurnal lama (jika ada) dibatalkan.
        if ($invoice->status === 'Draft') {
            return $this->reverseInvoice($invoice, 'Invoice dikembalikan ke draft', $actor);
        }

        $this->assertInvoiceNotLinkedToLuminash($invoice);

        return $this->transaction(function () use ($invoice, $actor): array {
            $total = (int) round((float) $invoice->total);
            $dp = (int) round((float) ($invoice->down_payment ?? 0));

            if ($dp < 0) {
                throw new DomainException('Total pembayaran DP tidak boleh negatif.');
            }

            if ($dp > $total) {
                throw new DomainException('Total pembayaran DP tidak boleh melebihi total tagihan invoice.');
            }

            $cashExpressId = $this->accountByCode('1001')->getKey();
            $receivableId = $this->accountByCode('1100')->getKey();
            $revenueId = $this->accountByCode('4000')->getKey();

            $issueDate = $this->dateString($invoice->invoice_date ?? $invoice->created_at ?? now());
            $dpDate = $this->dateString($invoice->dp_date ?? $invoice->invoice_date ?? $invoice->created_at ?? now());
            $settlementDate = $this->dateString(
                $invoice->event_date ?? $invoice->due_date ?? $invoice->invoice_date ?? now()
            );

            $recognizedRevenue = $this->recognizedSessionRevenue($invoice, $revenueId);
            $netRevenue = max(0, $total - $recognizedRevenue);

            $postedJournals = [];

            $issueJournal = $this->syncInvoiceStage(
                $invoice,
                $actor,
                'invoice:issue:'.$invoice->id,
                $netRevenue > 0,
                $netRevenue,
                $issueDate,
                "Pengakuan piutang invoice #{$invoice->invoice_number} - {$invoice->client_name}",
                [
                    ['chart_of_account_id' => $receivableId, 'debit' => $netRevenue, 'credit' => 0, 'description' => "Piutang invoice #{$invoice->invoice_number}"],
                    ['chart_of_account_id' => $revenueId, 'debit' => 0, 'credit' => $netRevenue, 'description' => "Pendapatan invoice #{$invoice->invoice_number}"],
                ],
                "Koreksi nilai piutang invoice #{$invoice->invoice_number}",
                "Pembatalan pengakuan piutang invoice #{$invoice->invoice_number}"
            );

            if ($issueJournal) {
                $postedJournals[] = $issueJournal;
            }

            $dpJournal = $this->syncInvoiceStage(
                $invoice,
                $actor,
                'invoice:dp:'.$invoice->id,
                $dp > 0,
                $dp,
                $dpDate,
                "Penerimaan DP invoice #{$invoice->invoice_number} - {$invoice->client_name}",
                [
                    ['chart_of_account_id' => $cashExpressId, 'debit' => $dp, 'credit' => 0, 'description' => "Kas masuk DP invoice #{$invoice->invoice_number}"],
                    ['chart_of_account_id' => $receivableId, 'debit' => 0, 'credit' => $dp, 'description' => "Pengurangan piutang DP #{$invoice->invoice_number}"],
                ],
                "Koreksi DP invoice #{$invoice->invoice_number}",
                "Pembatalan DP invoice #{$invoice->invoice_number}"
            );

            if ($dpJournal) {
                $postedJournals[] = $dpJournal;
            }

            $settlementAmount = $invoice->status === 'Lunas' ? max(0, $total - $dp) : 0;

            $settlementJournal = $this->syncInvoiceStage(
                $invoice,
                $actor,
                'invoice:settlement:'.$invoice->id,
                $settlementAmount > 0,
                $settlementAmount,
                $settlementDate,
                "Penerimaan pelunasan invoice #{$invoice->invoice_number} - {$invoice->client_name}",
                [
                    ['chart_of_account_id' => $cashExpressId, 'debit' => $settlementAmount, 'credit' => 0, 'description' => "Kas masuk pelunasan invoice #{$invoice->invoice_number}"],
                    ['chart_of_account_id' => $receivableId, 'debit' => 0, 'credit' => $settlementAmount, 'description' => "Pengurangan piutang pelunasan #{$invoice->invoice_number}"],
                ],
                "Koreksi pelunasan invoice #{$invoice->invoice_number}",
                "Pembatalan status lunas invoice #{$invoice->invoice_number}"
            );

            if ($settlementJournal) {
                $postedJournals[] = $settlementJournal;
            }

            return $postedJournals;
        });
    }

    /**
     * Reverse seluruh jurnal aktif milik invoice yang dibatalkan atau dihapus.
     *
     * @return array<int, JournalEntry>
     */
    public function reverseInvoice(Invoice $invoice, ?string $reason = null, ?Karyawan $actor = null): array
    {
        return $this->transaction(function () use ($invoice, $reason, $actor): array {
            $reversals = [];

            foreach ($this->currentJournals(self::REF_INVOICE, (string) $invoice->id) as $journal) {
                $reversals[] = $this->reverseJournal(
                    $journal,
                    $reason ?: "Pembatalan invoice #{$invoice->invoice_number}",
                    $actor
                );
            }

            return $reversals;
        });
    }

    /**
     * Sinkronkan rekap harian impor Luminash Golio ke jurnal umum.
     *
     * @param  array<int, string>  $dates
     * @return array<int, JournalEntry>
     */
    public function syncLuminashConsolidation(array $dates, ?Karyawan $actor = null): array
    {
        $dates = collect($dates)
            ->filter(fn ($date): bool => filled($date))
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($dates === []) {
            return [];
        }

        return $this->transaction(function () use ($dates, $actor): array {
            $golioAccount = FinancialAccount::query()
                ->where('name', LuminashImportService::ACCOUNT_NAME)
                ->first();
            $golioCoaId = $golioAccount?->chart_of_account_id ?: $this->accountByCode('1002')->getKey();
            $revenueCoaId = $this->accountByCode('4000')->getKey();

            $postedJournals = [];

            foreach ($dates as $date) {
                $rekapTransaction = FinancialTransaction::withTrashed()
                    ->where('source_type', LuminashImportService::SOURCE)
                    ->where('source_id', LuminashRekapService::REKAP_SOURCE_PREFIX.$date)
                    ->first();

                $baseKey = 'luminash:rekap:'.$date;
                $current = $this->currentJournal(self::REF_LUMINASH, null, $baseKey);

                if (! $rekapTransaction || $rekapTransaction->trashed() || (int) $rekapTransaction->amount <= 0) {
                    if ($current) {
                        $this->reverseJournal(
                            $current,
                            "Pembatalan rekap harian Luminash Golio {$date}",
                            $actor
                        );
                    }

                    continue;
                }

                $amount = (int) $rekapTransaction->amount;
                $journalDate = $rekapTransaction->transaction_date?->toDateString() ?? $date;

                $lines = [
                    ['chart_of_account_id' => $golioCoaId, 'debit' => $amount, 'credit' => 0, 'description' => "Kas masuk Luminash Golio {$date}"],
                    ['chart_of_account_id' => $revenueCoaId, 'debit' => 0, 'credit' => $amount, 'description' => "Pendapatan booth Luminash Golio {$date}"],
                ];

                if ($current && $this->journalMatches($current, $journalDate, $lines)) {
                    $postedJournals[] = $current;

                    continue;
                }

                if ($current) {
                    $this->reverseJournal(
                        $current,
                        "Pembaruan rekap harian Luminash Golio {$date}",
                        $actor
                    );
                }

                $postedJournals[] = $this->postingService->postJournal([
                    'journal_date' => $journalDate,
                    'description' => "Pendapatan rekap harian Luminash Golio {$date}",
                    'reference_type' => self::REF_LUMINASH,
                    'reference_id' => (string) $rekapTransaction->id,
                    'idempotency_key' => $this->versionedKey(
                        $baseKey,
                        self::REF_LUMINASH,
                        null
                    ),
                    'created_by' => $actor?->karyawan_id,
                ], $lines, $actor);
            }

            return $postedJournals;
        });
    }

    /**
     * Penyesuaian jurnal setelah transaksi komponen Luminash berubah atau dihapus.
     */
    public function syncLuminashAfterComponentChange(
        FinancialTransaction $transaction,
        ?Karyawan $actor = null
    ): void {
        if ($transaction->source_type !== LuminashImportService::SOURCE) {
            return;
        }

        $date = $transaction->transaction_date?->toDateString();

        if (blank($date)) {
            return;
        }

        if (! str_starts_with((string) $transaction->source_id, LuminashRekapService::REKAP_SOURCE_PREFIX)) {
            app(LuminashRekapService::class)->consolidateDates([$date], $actor);
        }

        $this->syncLuminashConsolidation([$date], $actor);
    }

    /**
     * Tentukan tanggal reversal: jika periode tanggal asli masih terbuka, gunakan tanggal asli.
     * Jika periode tanggal asli sudah closed, gunakan tanggal hari ini (periode berjalan yang terbuka).
     */
    public function resolveReversalDate(?Carbon $originalDate = null): string
    {
        $now = now()->startOfDay();

        if ($originalDate) {
            $period = FiscalPeriod::forDate($originalDate);

            if ($period && $period->isOpen()) {
                return $originalDate->toDateString();
            }
        }

        return $now->toDateString();
    }

    /**
     * Buat atau koreksi satu tahap jurnal invoice.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncInvoiceStage(
        Invoice $invoice,
        ?Karyawan $actor,
        string $baseKey,
        bool $shouldPost,
        int $amount,
        string $date,
        string $description,
        array $lines,
        string $correctionReason,
        string $cancelReason
    ): ?JournalEntry {
        $current = $this->currentJournal(self::REF_INVOICE, (string) $invoice->id, $baseKey);

        if (! $shouldPost || $amount <= 0) {
            if ($current) {
                $this->reverseJournal($current, $cancelReason, $actor);
            }

            return null;
        }

        if ($current && $this->journalMatches($current, $date, $lines)) {
            return $current;
        }

        if ($current) {
            $this->reverseJournal($current, $correctionReason, $actor);
        }

        return $this->postingService->postJournal([
            'journal_date' => $date,
            'description' => $description,
            'reference_type' => self::REF_INVOICE,
            'reference_id' => (string) $invoice->id,
            'idempotency_key' => $this->versionedKey($baseKey, self::REF_INVOICE, (string) $invoice->id),
            'created_by' => $actor?->karyawan_id,
        ], $lines, $actor);
    }

    /**
     * Cegah invoice mengakui pendapatan yang sudah diakui rekap Luminash/Golio.
     */
    private function assertInvoiceNotLinkedToLuminash(Invoice $invoice): void
    {
        $golioCabangId = LuminashImportService::golioCabangId();

        $linked = SessionReport::query()
            ->where('invoice_id', $invoice->id)
            ->where(function (Builder $query) use ($golioCabangId): void {
                if (filled($golioCabangId)) {
                    $query->where('cabang_id', $golioCabangId);
                }

                $query->orWhere('event_note', 'like', 'Impor Luminash CSV%')
                    ->orWhere('event_note', 'like', 'Tambahan impor Luminash CSV%');
            })
            ->exists();

        if ($linked) {
            throw new DomainException(
                'Invoice tidak dapat menggunakan pendapatan rekap Luminash/Golio karena sudah diakui pada jurnal rekap harian.'
            );
        }
    }

    /**
     * Total pendapatan rekap sesi yang sudah diakui (setelah memperhitungkan reversal).
     */
    private function recognizedSessionRevenue(Invoice $invoice, int $revenueAccountId): int
    {
        $linkedReportIds = SessionReport::query()
            ->where('invoice_id', $invoice->id)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($linkedReportIds === []) {
            return 0;
        }

        $netRevenue = JournalLine::query()
            ->where('chart_of_account_id', $revenueAccountId)
            ->whereHas('journalEntry', function (Builder $query) use ($linkedReportIds): void {
                $query->where('reference_type', self::REF_SESSION_REPORT)
                    ->whereIn('reference_id', $linkedReportIds)
                    ->where('status', JournalEntry::STATUS_POSTED);
            })
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net_revenue')
            ->value('net_revenue');

        return max(0, (int) round((float) $netRevenue));
    }

    /**
     * Jurnal aktif (posted, bukan reversal, dan belum direversal) untuk sebuah referensi.
     */
    private function currentJournal(
        string $referenceType,
        ?string $referenceId,
        string $baseKey
    ): ?JournalEntry {
        return $this->currentJournals($referenceType, $referenceId, $baseKey)->first();
    }

    /**
     * @return EloquentCollection<int, JournalEntry>
     */
    private function currentJournals(
        string $referenceType,
        ?string $referenceId,
        ?string $baseKey = null
    ): EloquentCollection {
        return JournalEntry::query()
            ->with('lines')
            ->where('reference_type', $referenceType)
            ->when($referenceId !== null, fn (Builder $query): Builder => $query->where('reference_id', $referenceId))
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->when($baseKey !== null, fn (Builder $query): Builder => $query->where(function (Builder $inner) use ($baseKey): void {
                $inner->where('idempotency_key', $baseKey)
                    ->orWhere('idempotency_key', 'like', $baseKey.':v%');
            }))
            ->whereDoesntHave('reversals', fn (Builder $query): Builder => $query->where('status', JournalEntry::STATUS_POSTED))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();
    }

    private function reverseJournal(JournalEntry $journal, string $reason, ?Karyawan $actor = null): JournalEntry
    {
        return $this->postingService->reverse(
            $journal,
            $reason,
            $this->resolveReversalDate($journal->journal_date),
            $actor
        );
    }

    /**
     * Periksa apakah isi jurnal aktif sama dengan rencana jurnal.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function journalMatches(JournalEntry $entry, string $date, array $lines): bool
    {
        if ($entry->journal_date?->toDateString() !== $date) {
            return false;
        }

        $entry->loadMissing('lines');

        $actual = $entry->lines
            ->map(fn (JournalLine $line): array => [
                'chart_of_account_id' => (int) $line->chart_of_account_id,
                'debit' => $this->cents($line->debit),
                'credit' => $this->cents($line->credit),
            ])
            ->all();

        $expected = array_map(fn (array $line): array => [
            'chart_of_account_id' => (int) $line['chart_of_account_id'],
            'debit' => $this->cents($line['debit'] ?? 0),
            'credit' => $this->cents($line['credit'] ?? 0),
        ], $lines);

        return $this->lineSignature($actual) === $this->lineSignature($expected);
    }

    /**
     * @param  array<int, array{chart_of_account_id: int, debit: int, credit: int}>  $lines
     * @return array<int, string>
     */
    private function lineSignature(array $lines): array
    {
        $signature = array_map(
            fn (array $line): string => $line['chart_of_account_id'].':'.$line['debit'].':'.$line['credit'],
            $lines
        );

        sort($signature);

        return $signature;
    }

    /**
     * Idempotency key versi baru yang tidak bertabrakan dengan jurnal sebelumnya.
     */
    private function versionedKey(string $baseKey, string $referenceType, ?string $referenceId): string
    {
        $maxId = JournalEntry::query()
            ->where('reference_type', $referenceType)
            ->when($referenceId !== null, fn (Builder $query): Builder => $query->where('reference_id', $referenceId))
            ->max('id');

        return $maxId ? $baseKey.':v'.$maxId : $baseKey;
    }

    /**
     * @param  array<int, string>  $baseKeys
     */
    private function belongsToAnyBaseKey(?string $idempotencyKey, array $baseKeys): bool
    {
        if (blank($idempotencyKey)) {
            return false;
        }

        foreach ($baseKeys as $baseKey) {
            if ($idempotencyKey === $baseKey || str_starts_with($idempotencyKey, $baseKey.':v')) {
                return true;
            }
        }

        return false;
    }

    private function isManagedByAnotherSource(FinancialTransaction $transaction): bool
    {
        return in_array($transaction->source_type, [
            InvoiceFinancialService::SOURCE,
            SessionReportFinancialService::SOURCE,
            LuminashImportService::SOURCE,
        ], true);
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->toDateString()
            : Carbon::parse($value)->toDateString();
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * Transaksi database dengan retry terbatas untuk deadlock/unique race.
     */
    private function transaction(callable $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        $attempt = 0;

        while (true) {
            try {
                return DB::transaction($callback);
            } catch (\Throwable $exception) {
                $attempt++;

                if (! $this->isRetryableConcurrencyError($exception) || $attempt >= 3) {
                    throw $exception;
                }

                usleep(50000 * $attempt);
            }
        }
    }

    private function isRetryableConcurrencyError(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'duplicate key value violates unique constraint')
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'deadlock detected')
            || str_contains($message, 'Serialization failure')
            || str_contains($message, 'Lock wait timeout');
    }
}
