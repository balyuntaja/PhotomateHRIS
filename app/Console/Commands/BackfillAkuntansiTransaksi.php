<?php

namespace App\Console\Commands;

use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\InvoiceFinancialService;
use App\Services\LuminashImportService;
use App\Services\LuminashRekapService;
use App\Services\SessionReportFinancialService;
use App\Services\TransactionAccountingService;
use App\Utils\RupiahHelper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class BackfillAkuntansiTransaksi extends Command
{
    protected $signature = 'akuntansi:backfill-transaksi
                            {--dry-run : Tampilkan ringkasan rekonsiliasi tanpa menyimpan perubahan}
                            {--from= : Filter tanggal transaksi mulai (YYYY-MM-DD)}
                            {--to= : Filter tanggal transaksi selesai (YYYY-MM-DD)}
                            {--account= : Filter nama atau ID akun kas/bank operasional}
                            {--actor= : ID karyawan pencatat backfill (opsional)}';

    protected $description = 'Sinkronkan transaksi operasional existing ke jurnal akuntansi secara idempotent';

    private bool $dryRun = false;

    private ?Karyawan $actor = null;

    /**
     * @var array<string, int|float>
     */
    private array $stats = [];

    /**
     * @var array<int, array<int, string>>
     */
    private array $skippedRows = [];

    /**
     * @var array<string, string>
     */
    private array $processedReports = [];

    /**
     * @var array<string, bool>
     */
    private array $processedLuminashDates = [];

    private ?string $fromDate = null;

    private ?string $toDate = null;

    private ?string $accountFilter = null;

    public function handle(TransactionAccountingService $accountingService): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->stats = [
            'processed' => 0,
            'posted' => 0,
            'existing' => 0,
            'unmapped' => 0,
            'components' => 0,
            'skipped' => 0,
            'debit' => 0,
            'credit' => 0,
        ];
        $this->skippedRows = [];
        $this->processedReports = [];
        $this->processedLuminashDates = [];

        if (! $this->resolveActor()) {
            return self::FAILURE;
        }

        $fromDate = $this->normalizeDateOption('from');

        if ($fromDate === false || ($toDate = $this->normalizeDateOption('to')) === false) {
            $this->error('Format --from / --to harus YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($fromDate && $toDate && $fromDate > $toDate) {
            $this->error('Tanggal --from tidak boleh setelah --to.');

            return self::FAILURE;
        }

        if ($this->dryRun) {
            $this->warn('MODE DRY-RUN: simulasi backfill, tidak ada jurnal yang disimpan.');
        }

        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->accountFilter = $this->option('account');

        $query = $this->transactionQuery($fromDate, $toDate);
        $totalTransactions = (clone $query)->count();

        if ($totalTransactions === 0) {
            $this->info('Tidak ada transaksi operasional yang memenuhi kriteria filter.');

            return self::SUCCESS;
        }

        $this->info("Memproses {$totalTransactions} transaksi operasional...");

        $startedAt = now();

        $bar = $this->output->createProgressBar($totalTransactions);
        $bar->start();

        $query->chunkById(100, function (Collection $transactions) use ($accountingService, $bar): void {
            foreach ($transactions as $transaction) {
                $bar->advance();
                $this->stats['processed']++;

                try {
                    $this->processTransaction($transaction, $accountingService);
                } catch (\Throwable $exception) {
                    $this->stats['skipped']++;
                    $this->addSkippedRow($transaction, 'Gagal diproses: '.$exception->getMessage());
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        $totalReversals = JournalEntry::query()
            ->whereNotNull('reversal_of_id')
            ->where('created_at', '>=', $startedAt)
            ->count();

        $this->renderSummary($totalReversals);
        $this->renderSkippedRows();
        $this->renderAccountReconciliation();

        return self::SUCCESS;
    }

    private function processTransaction(
        FinancialTransaction $transaction,
        TransactionAccountingService $accountingService
    ): void {
        if ($transaction->source_type === InvoiceFinancialService::SOURCE) {
            $this->processInvoice($transaction, $accountingService);

            return;
        }

        if ($transaction->source_type === LuminashImportService::SOURCE) {
            $this->processLuminash($transaction, $accountingService);

            return;
        }

        if ($transaction->source_type === SessionReportFinancialService::SOURCE) {
            $this->processSessionReport($transaction, $accountingService);

            return;
        }

        $this->processManual($transaction, $accountingService);
    }

    private function processInvoice(
        FinancialTransaction $transaction,
        TransactionAccountingService $accountingService
    ): void {
        $invoice = Invoice::query()->find($transaction->source_id);

        if (! $invoice) {
            $this->skipOther($transaction, 'Invoice sumber tidak ditemukan');

            return;
        }

        $before = $this->effectiveJournals(TransactionAccountingService::REF_INVOICE, (string) $invoice->id);

        if ($before->isNotEmpty()) {
            $this->stats['existing']++;
        }

        if ($this->dryRun) {
            if ($before->isNotEmpty()) {
                $this->addJournalTotals($before);

                return;
            }

            if (in_array($invoice->status, ['Draft', 'Dibatalkan'], true)) {
                $this->skipOther($transaction, 'Invoice berstatus '.$invoice->status.' belum menghasilkan jurnal');

                return;
            }

            $total = (int) round((float) $invoice->total);
            $dp = (int) round((float) ($invoice->down_payment ?? 0));
            $settlement = $invoice->status === 'Lunas' ? max(0, $total - $dp) : 0;
            $debit = $total + $dp + $settlement;

            $this->stats['posted']++;
            $this->stats['debit'] += $debit * 100;
            $this->stats['credit'] += $debit * 100;

            return;
        }

        $accountingService->syncInvoice($invoice, $this->actor);

        $after = $this->effectiveJournals(TransactionAccountingService::REF_INVOICE, (string) $invoice->id);

        if ($before->isEmpty() && $after->isNotEmpty()) {
            $this->stats['posted']++;
        }

        if ($after->isEmpty()) {
            $this->skipOther($transaction, 'Invoice tidak menghasilkan jurnal (status '.$invoice->status.')');

            return;
        }

        $this->addJournalTotals($after);
    }

    private function processLuminash(
        FinancialTransaction $transaction,
        TransactionAccountingService $accountingService
    ): void {
        $sourceId = (string) $transaction->source_id;

        if (! str_starts_with($sourceId, LuminashRekapService::REKAP_SOURCE_PREFIX)) {
            $this->stats['components']++;

            return;
        }

        $date = substr($sourceId, strlen(LuminashRekapService::REKAP_SOURCE_PREFIX));

        if (isset($this->processedLuminashDates[$date])) {
            return;
        }

        $this->processedLuminashDates[$date] = true;

        $baseKey = 'luminash:rekap:'.$date;
        $before = $this->effectiveJournals(TransactionAccountingService::REF_LUMINASH, null, $baseKey);

        if ($before->isNotEmpty()) {
            $this->stats['existing']++;
        }

        if ($this->dryRun) {
            if ($before->isNotEmpty()) {
                $this->addJournalTotals($before);

                return;
            }

            $amount = (int) $transaction->amount;

            if ($amount <= 0) {
                $this->skipOther($transaction, 'Rekap Luminash tidak memiliki nominal');

                return;
            }

            $this->stats['posted']++;
            $this->stats['debit'] += $amount * 100;
            $this->stats['credit'] += $amount * 100;

            return;
        }

        $accountingService->syncLuminashConsolidation([$date], $this->actor);

        $after = $this->effectiveJournals(TransactionAccountingService::REF_LUMINASH, null, $baseKey);

        if ($before->isEmpty() && $after->isNotEmpty()) {
            $this->stats['posted']++;
        }

        if ($after->isEmpty()) {
            $this->skipOther($transaction, 'Rekap Luminash tidak menghasilkan jurnal');

            return;
        }

        $this->addJournalTotals($after);
    }

    private function processSessionReport(
        FinancialTransaction $transaction,
        TransactionAccountingService $accountingService
    ): void {
        $reportId = explode('-', (string) $transaction->source_id)[0];

        if (blank($reportId)) {
            $this->skipOther($transaction, 'Referensi rekap sesi tidak valid');

            return;
        }

        if (isset($this->processedReports[$reportId])) {
            $outcome = $this->processedReports[$reportId];

            if ($outcome === 'posted') {
                $this->stats['posted']++;
            } elseif ($outcome === 'existing') {
                $this->stats['existing']++;
            } else {
                $this->stats['skipped']++;
            }

            return;
        }

        $this->processedReports[$reportId] = 'skipped';

        $report = SessionReport::query()->find($reportId);

        if (! $report) {
            $this->skipOther($transaction, 'Rekap sesi sumber tidak ditemukan');

            return;
        }

        $before = $this->effectiveJournals(TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id);

        if ($this->dryRun) {
            if ($before->isNotEmpty()) {
                $this->stats['existing']++;
                $this->processedReports[$reportId] = 'existing';
                $this->addJournalTotals($before);

                return;
            }

            if ($report->status !== SessionReport::STATUS_APPROVED) {
                $this->skipOther($transaction, 'Rekap sesi berstatus '.$report->status.' belum menghasilkan jurnal');

                return;
            }

            $amount = (int) array_sum(
                app(SessionReportFinancialService::class)->expectedSources($report)
            );

            if ($amount <= 0) {
                $this->skipOther($transaction, 'Rekap sesi tidak menghasilkan pendapatan');

                return;
            }

            $this->stats['posted']++;
            $this->processedReports[$reportId] = 'posted';
            $this->stats['debit'] += $amount * 100;
            $this->stats['credit'] += $amount * 100;

            return;
        }

        $accountingService->syncSessionReport($report, $this->actor);

        $after = $this->effectiveJournals(TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id);

        if ($after->isEmpty()) {
            $this->skipOther($transaction, 'Rekap sesi tidak menghasilkan jurnal (status '.$report->status.')');

            return;
        }

        if ($before->isNotEmpty()) {
            $this->stats['existing']++;
            $this->processedReports[$reportId] = 'existing';
        } else {
            $this->stats['posted']++;
            $this->processedReports[$reportId] = 'posted';
        }

        $this->addJournalTotals($after);
    }

    private function processManual(
        FinancialTransaction $transaction,
        TransactionAccountingService $accountingService
    ): void {
        $before = $this->effectiveJournals(
            TransactionAccountingService::REF_FINANCIAL_TRANSACTION,
            (string) $transaction->id
        );

        if ($before->isNotEmpty()) {
            $this->stats['existing']++;
            $this->addJournalTotals($before);

            return;
        }

        $plan = $accountingService->planFinancialTransaction($transaction);

        if ($plan['error'] !== null) {
            if (str_contains($plan['error'], 'Mapping kategori')) {
                $this->stats['unmapped']++;
            } else {
                $this->stats['skipped']++;
            }

            $this->addSkippedRow($transaction, $plan['error']);

            return;
        }

        if ($this->dryRun) {
            $amount = (int) $transaction->amount;
            $this->stats['posted']++;
            $this->stats['debit'] += $amount * 100;
            $this->stats['credit'] += $amount * 100;

            return;
        }

        $journal = $accountingService->syncFinancialTransaction($transaction, $this->actor);

        if (! $journal) {
            $this->skipOther($transaction, 'Gagal memposting jurnal transaksi');

            return;
        }

        $after = $this->effectiveJournals(
            TransactionAccountingService::REF_FINANCIAL_TRANSACTION,
            (string) $transaction->id
        );

        $this->stats['posted']++;
        $this->addJournalTotals($after);
    }

    private function resolveActor(): bool
    {
        $actorId = $this->option('actor');

        if (blank($actorId)) {
            return true;
        }

        $this->actor = Karyawan::query()->where('karyawan_id', $actorId)->first();

        if (! $this->actor) {
            $this->error("Karyawan dengan ID '{$actorId}' tidak ditemukan.");

            return false;
        }

        return true;
    }

    private function normalizeDateOption(string $name): string|false|null
    {
        $value = $this->option($name);

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return false;
        }
    }

    private function transactionQuery(?string $fromDate, ?string $toDate): Builder
    {
        $query = FinancialTransaction::query()
            ->whereNull('deleted_at');

        if ($fromDate) {
            $query->whereDate('transaction_date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('transaction_date', '<=', $toDate);
        }

        $accountFilter = $this->option('account');

        if (blank($accountFilter)) {
            return $query;
        }

        if (is_numeric($accountFilter)) {
            return $query->where('account_id', (int) $accountFilter);
        }

        $accountIds = FinancialAccount::query()
            ->where('name', 'like', '%'.$accountFilter.'%')
            ->pluck('id');

        return $query->whereIn('account_id', $accountIds);
    }

    private function effectiveJournals(
        string $referenceType,
        ?string $referenceId,
        ?string $baseKey = null
    ): Collection {
        return JournalEntry::query()
            ->where('reference_type', $referenceType)
            ->when($referenceId !== null, fn (Builder $query): Builder => $query->where('reference_id', $referenceId))
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->when($baseKey !== null, fn (Builder $query): Builder => $query->where(function (Builder $inner) use ($baseKey): void {
                $inner->where('idempotency_key', $baseKey)
                    ->orWhere('idempotency_key', 'like', $baseKey.':v%');
            }))
            ->whereDoesntHave('reversals', fn (Builder $query): Builder => $query->where('status', JournalEntry::STATUS_POSTED))
            ->orderBy('id')
            ->get();
    }

    private function addJournalTotals(Collection $journals): void
    {
        foreach ($journals as $journal) {
            $this->stats['debit'] += (int) round(((float) $journal->total_debit) * 100);
            $this->stats['credit'] += (int) round(((float) $journal->total_credit) * 100);
        }
    }

    private function skipOther(FinancialTransaction $transaction, string $reason): void
    {
        $this->stats['skipped']++;
        $this->addSkippedRow($transaction, $reason);
    }

    private function addSkippedRow(FinancialTransaction $transaction, string $reason): void
    {
        $this->skippedRows[] = [
            (string) $transaction->id,
            $transaction->transaction_date?->toDateString() ?? '-',
            $transaction->type_label,
            $transaction->category_label,
            RupiahHelper::format($transaction->amount),
            $reason,
        ];
    }

    private function renderSummary(int $totalReversals): void
    {
        $balance = $this->stats['debit'] === $this->stats['credit'] ? 'SEIMBANG (OK)' : 'TIDAK SEIMBANG';
        $skippedTotal = $this->stats['unmapped'] + $this->stats['skipped'];

        $this->info('=== RINGKASAN BACKFILL AKUNTANSI TRANSAKSI ===');
        $this->table(['Metrik', 'Nilai'], [
            ['Total Transaksi Operasional Diproses', number_format((int) $this->stats['processed'])],
            ['Total Transaksi Berhasil Diposting', number_format((int) $this->stats['posted'])],
            ['Total Transaksi Sudah Memiliki Jurnal', number_format((int) $this->stats['existing'])],
            ['Total Transaksi Tanpa Mapping Kategori', number_format((int) $this->stats['unmapped'])],
            ['Total Komponen Luminash (tidak diposting mandiri)', number_format((int) $this->stats['components'])],
            ['Total Transaksi Dilewati Lainnya', number_format((int) $this->stats['skipped'])],
            ['Total Transaksi Belum Terposting', number_format($skippedTotal)],
            ['Total Debit Jurnal', RupiahHelper::format($this->stats['debit'] / 100)],
            ['Total Credit Jurnal', RupiahHelper::format($this->stats['credit'] / 100)],
            ['Status Keseimbangan (Debit == Credit)', $balance],
            ['Total Reversal Jurnal', number_format($totalReversals)],
        ]);
    }

    private function renderSkippedRows(): void
    {
        if ($this->skippedRows === []) {
            return;
        }

        $this->newLine();
        $this->warn('Daftar Transaksi yang Dilewati / Tanpa Mapping (Maksimal 20 sampel):');
        $this->table([
            'ID Transaksi',
            'Tanggal',
            'Tipe',
            'Kategori',
            'Nominal',
            'Alasan Dilewati',
        ], array_slice($this->skippedRows, 0, 20));

        if (count($this->skippedRows) > 20) {
            $this->comment('... dan '.(count($this->skippedRows) - 20).' transaksi lainnya.');
        }
    }

    private function renderAccountReconciliation(): void
    {
        $accountsQuery = FinancialAccount::query()
            ->with('chartOfAccount')
            ->orderBy('sort_order')
            ->orderBy('name');

        if (filled($this->accountFilter)) {
            if (is_numeric($this->accountFilter)) {
                $accountsQuery->whereKey((int) $this->accountFilter);
            } else {
                $accountsQuery->where('name', 'like', '%'.$this->accountFilter.'%');
            }
        }

        $accounts = $accountsQuery->get();

        if ($accounts->isEmpty()) {
            return;
        }

        $rows = [];

        foreach ($accounts as $account) {
            $operation = FinancialTransaction::query()
                ->whereNull('deleted_at')
                ->where('account_id', $account->id)
                ->when($this->fromDate, fn (Builder $query): Builder => $query->whereDate('transaction_date', '>=', $this->fromDate))
                ->when($this->toDate, fn (Builder $query): Builder => $query->whereDate('transaction_date', '<=', $this->toDate))
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income, "
                    ."COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense"
                )
                ->first();

            $opIncome = (int) ($operation?->income ?? 0);
            $opExpense = (int) ($operation?->expense ?? 0);
            $netOperation = $opIncome - $opExpense;

            $journalDebit = 0;
            $journalCredit = 0;

            if ($account->chart_of_account_id) {
                $lines = JournalLine::query()
                    ->where('chart_of_account_id', $account->chart_of_account_id)
                    ->whereHas('journalEntry', function (Builder $query): void {
                        $query->where('status', JournalEntry::STATUS_POSTED)
                            ->when($this->fromDate, fn (Builder $inner): Builder => $inner->whereDate('journal_date', '>=', $this->fromDate))
                            ->when($this->toDate, fn (Builder $inner): Builder => $inner->whereDate('journal_date', '<=', $this->toDate));
                    })
                    ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
                    ->first();

                $journalDebit = (int) round((float) ($lines?->debit ?? 0));
                $journalCredit = (int) round((float) ($lines?->credit ?? 0));
            }

            $netJournal = $journalDebit - $journalCredit;
            $difference = $netOperation - $netJournal;

            $rows[] = [
                $account->name.' (COA: '.($account->chartOfAccount?->code ?? '-').')',
                RupiahHelper::format($opIncome),
                RupiahHelper::format($opExpense),
                RupiahHelper::format($netOperation),
                RupiahHelper::format($netJournal),
                RupiahHelper::format($difference),
                $difference === 0 ? 'COCOK' : 'SELISIH',
            ];
        }

        $this->newLine();
        $this->info('=== REKONSILIASI AKUN OPERASIONAL VS JURNAL AKUNTANSI (SESUAI FILTER) ===');
        $this->table([
            'Akun Operasional',
            'Mutasi Masuk Op',
            'Mutasi Keluar Op',
            'Net Kas Operasional',
            'Net Kas Jurnal',
            'Selisih',
            'Status',
        ], $rows);
    }
}
