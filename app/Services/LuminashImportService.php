<?php

namespace App\Services;

use App\Models\ApprovalLog;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\LuminashImportBatch;
use App\Models\LuminashImportRow;
use App\Models\Perusahaan;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LuminashImportService
{
    public const SOURCE = 'luminash_csv';

    public const SOURCE_LABEL = 'Luminash CSV';

    public const ACCOUNT_NAME = 'Golio';

    public const CABANG_NAME = 'Golio';

    public static function golioCabangId(): ?string
    {
        return Cabang::query()->where('nama_cabang', self::CABANG_NAME)->value('cabang_id');
    }

    private const TIMESTAMP_FORMAT = 'd M Y H:i';

    private const REQUIRED_COLUMNS = ['order_id', 'timestamp', 'amount', 'status'];

    private const HEADER_ALIASES = [
        'orderid' => 'order_id',
        'order' => 'order_id',
        'timestamp' => 'timestamp',
        'waktu' => 'timestamp',
        'boothname' => 'booth_name',
        'booth' => 'booth_name',
        'paymentmethod' => 'payment_method',
        'paymenttype' => 'payment_type',
        'amount' => 'amount',
        'nominal' => 'amount',
        'printcount' => 'print_count',
        'extraprints' => 'extra_prints',
        'status' => 'status',
        'framename' => 'frame_name',
        'frame' => 'frame_name',
        'vouchercode' => 'voucher_code',
        'voucher' => 'voucher_code',
        'email' => 'email',
        'consent' => 'consent',
        'qrurl' => 'qr_url',
    ];

    private const PAYMENT_METHOD_ALIASES = [
        'tunai' => 'cash',
        'ewallet' => 'e-wallet',
        'debit' => 'debit/kredit',
        'kredit' => 'debit/kredit',
    ];

    /**
     * Baca file CSV Luminash dan petakan kolom berdasarkan nama header.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $path, string $disk = 'local'): array
    {
        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === false || $stream === null) {
            throw new RuntimeException('File CSV tidak dapat dibaca. Silakan unggah ulang file.');
        }

        $mapping = [];
        $rows = [];
        $line = 0;

        while (($data = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;

            if ($line === 1) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($data[0] ?? ''));

                foreach ($data as $index => $header) {
                    $key = $this->normalizeKey((string) $header);

                    if (isset(self::HEADER_ALIASES[$key])) {
                        $mapping[self::HEADER_ALIASES[$key]] ??= $index;
                    }
                }

                continue;
            }

            if ($this->isBlankRow($data)) {
                continue;
            }

            $rows[] = $this->mapRow($data, $mapping, $line);
        }

        fclose($stream);

        if ($mapping === []) {
            throw new RuntimeException('Header CSV tidak dikenali. Pastikan file berasal dari export Transactions Luminash.');
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($mapping)));

        if ($missing !== []) {
            throw new RuntimeException('Kolom wajib tidak ditemukan pada CSV: ' . implode(', ', $this->columnLabels($missing)) . '.');
        }

        if (! array_key_exists('voucher_code', $mapping)) {
            throw new RuntimeException('Kolom "Voucher Code" tidak ditemukan pada CSV. Impor dihentikan agar transaksi bervoucher tidak ikut masuk ke rekap keuangan.');
        }

        if ($rows === []) {
            throw new RuntimeException('File CSV tidak berisi data transaksi.');
        }

        return $rows;
    }

    /**
     * Validasi dan klasifikasi setiap baris (server-side, tidak memakai data dari frontend).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function analyze(array $rows): array
    {
        $existingOrderIds = $this->existingOrderIds(array_column($rows, 'order_id'));
        $paymentMethods = $this->paymentMethodMap();
        $seenOrderIds = [];

        $analyzed = [];
        $summary = [
            'total_rows' => count($rows),
            'imported' => 0,
            'excluded_incomplete' => 0,
            'excluded_unknown_status' => 0,
            'excluded_voucher' => 0,
            'duplicate' => 0,
            'invalid' => 0,
            'total_amount' => 0,
            'by_payment_method' => [],
            'total_prints' => 0,
            'total_extra_prints' => 0,
            'period_start' => null,
            'period_end' => null,
        ];

        foreach ($rows as $row) {
            $entry = $row + [
                'result' => null,
                'reason' => null,
                'transaction_at' => null,
                'amount' => null,
                'payment_method_id' => null,
                'session_payment_method' => null,
            ];

            $entry['transaction_at'] = $this->parseTimestamp($row['timestamp_raw'] ?? null);
            $entry['amount'] = $this->parseAmount($row['amount_raw'] ?? null);
            $entry['print_count'] = $this->parseInteger($row['print_count_raw'] ?? null);
            $entry['extra_prints'] = $this->parseInteger($row['extra_prints_raw'] ?? null);

            if ($entry['transaction_at'] !== null) {
                $date = $entry['transaction_at']->toDateString();

                $summary['period_start'] = $summary['period_start'] === null || $date < $summary['period_start']
                    ? $date
                    : $summary['period_start'];

                $summary['period_end'] = $summary['period_end'] === null || $date > $summary['period_end']
                    ? $date
                    : $summary['period_end'];
            }

            $status = $this->normalizeKey((string) ($row['status_raw'] ?? ''));

            if ($status !== 'complete') {
                $isIncomplete = $status === 'incomplete';

                $entry['result'] = $isIncomplete
                    ? LuminashImportRow::RESULT_EXCLUDED_INCOMPLETE
                    : LuminashImportRow::RESULT_EXCLUDED_UNKNOWN_STATUS;

                $entry['reason'] = $isIncomplete
                    ? 'Status transaksi Incomplete.'
                    : 'Status "' . ($row['status_raw'] ?? '-') . '" belum dipetakan.';

                $summary[$isIncomplete ? 'excluded_incomplete' : 'excluded_unknown_status']++;
                $analyzed[] = $entry;

                continue;
            }

            if ($this->hasVoucher($row)) {
                $code = $this->normalizeVoucherCode($row['voucher_code'] ?? null);

                $entry['result'] = LuminashImportRow::RESULT_EXCLUDED_VOUCHER;
                $entry['reason'] = 'Transaksi menggunakan voucher' . ($code ? ' (' . $code . ')' : '') . '.';

                $summary['excluded_voucher']++;
                $analyzed[] = $entry;

                continue;
            }

            $errors = [];

            if (blank($row['order_id'])) {
                $errors[] = 'Order ID kosong.';
            } elseif (strlen((string) $row['order_id']) > 50) {
                $errors[] = 'Order ID melebihi 50 karakter.';
            }

            if ($entry['transaction_at'] === null) {
                $errors[] = $row['timestamp_raw'] === null
                    ? 'Tanggal transaksi kosong.'
                    : 'Format tanggal "' . $row['timestamp_raw'] . '" tidak dikenal.';
            }

            if ($entry['amount'] === null) {
                $errors[] = $row['amount_raw'] === null
                    ? 'Nominal kosong.'
                    : 'Nominal "' . $row['amount_raw'] . '" tidak valid.';
            } elseif ($entry['amount'] <= 0) {
                $errors[] = 'Nominal harus lebih dari 0.';
            }

            if (blank($row['payment_method'])) {
                $errors[] = 'Metode pembayaran kosong.';
            } else {
                $entry['payment_method_id'] = $paymentMethods[$this->normalizeMethodKey($row['payment_method'])] ?? null;

                if ($entry['payment_method_id'] === null) {
                    $errors[] = 'Metode pembayaran "' . $row['payment_method'] . '" belum dipetakan ke Photomate.';
                }

                $entry['session_payment_method'] = $this->sessionPaymentMethod($row['payment_method']);

                if ($entry['session_payment_method'] === null) {
                    $errors[] = 'Metode pembayaran "' . $row['payment_method'] . '" belum didukung untuk rekap sesi (hanya Tunai/QRIS).';
                }
            }

            if ($errors !== []) {
                $entry['result'] = LuminashImportRow::RESULT_INVALID;
                $entry['reason'] = implode(' ', $errors);

                $summary['invalid']++;
                $analyzed[] = $entry;

                continue;
            }

            $orderId = (string) $row['order_id'];

            if (in_array($orderId, $existingOrderIds, true)) {
                $entry['result'] = LuminashImportRow::RESULT_DUPLICATE;
                $entry['reason'] = 'Order ID sudah pernah diimpor sebelumnya.';

                $summary['duplicate']++;
                $analyzed[] = $entry;

                continue;
            }

            if (isset($seenOrderIds[$orderId])) {
                $entry['result'] = LuminashImportRow::RESULT_DUPLICATE;
                $entry['reason'] = 'Order ID duplikat di dalam file ini.';

                $summary['duplicate']++;
                $analyzed[] = $entry;

                continue;
            }

            $seenOrderIds[$orderId] = true;

            $entry['result'] = LuminashImportRow::RESULT_IMPORTED;
            $summary['imported']++;
            $summary['total_amount'] += (int) $entry['amount'];
            $summary['total_prints'] += (int) ($entry['print_count'] ?? 0);
            $summary['total_extra_prints'] += (int) ($entry['extra_prints'] ?? 0);

            $method = (string) $entry['session_payment_method'];
            $summary['by_payment_method'][$method]['count'] = ($summary['by_payment_method'][$method]['count'] ?? 0) + 1;
            $summary['by_payment_method'][$method]['amount'] = ($summary['by_payment_method'][$method]['amount'] ?? 0) + (int) $entry['amount'];

            $analyzed[] = $entry;
        }

        return [
            'rows' => $analyzed,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function analyzeFile(string $path, string $disk = 'local'): array
    {
        return $this->analyze($this->parse($path, $disk));
    }

    /**
     * Simpan batch import, transaksi keuangan, dan rekap sesi per tanggal dalam satu database transaction.
     *
     * Kategori transaksi selalu "Pendapatan Booth" (ditentukan backend, bukan input pengguna).
     *
     * @param  array<int, string>  $crewIds  Crew yang dicatat pada setiap rekap sesi hasil impor.
     */
    public function import(
        string $path,
        string $disk,
        Karyawan $user,
        ?string $originalFileName = null,
        array $crewIds = [],
    ): LuminashImportBatch {
        $category = FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->first();

        if (! $category) {
            throw new RuntimeException(
                'Kategori "' . SessionReportFinancialService::CATEGORY_NAME . '" belum tersedia di master kategori keuangan. '
                . 'Tambahkan kategori tersebut terlebih dahulu sebelum melakukan impor.'
            );
        }

        $account = FinancialAccount::query()->where('name', self::ACCOUNT_NAME)->first();

        if (! $account) {
            throw new RuntimeException('Akun "' . self::ACCOUNT_NAME . '" tidak ditemukan pada master akun keuangan.');
        }

        $cabang = $this->ensureGolioCabang();

        $analysis = $this->analyzeFile($path, $disk);
        $summary = $analysis['summary'];
        $fileName = $originalFileName ?: basename($path);

        $this->assertDatesOpenForUser($analysis['rows'], $user);

        return DB::transaction(function () use ($analysis, $summary, $path, $category, $account, $cabang, $user, $fileName, $crewIds): LuminashImportBatch {
            $batch = LuminashImportBatch::create([
                'file_name' => $fileName,
                'file_path' => $path,
                'cabang_id' => $cabang->cabang_id,
                'imported_by' => $user->karyawan_id,
                'period_start' => $summary['period_start'],
                'period_end' => $summary['period_end'],
                'total_rows' => $summary['total_rows'],
            ]);

            $counts = [
                LuminashImportRow::RESULT_IMPORTED => 0,
                LuminashImportRow::RESULT_EXCLUDED_INCOMPLETE => 0,
                LuminashImportRow::RESULT_EXCLUDED_UNKNOWN_STATUS => 0,
                LuminashImportRow::RESULT_EXCLUDED_VOUCHER => 0,
                LuminashImportRow::RESULT_DUPLICATE => 0,
                LuminashImportRow::RESULT_INVALID => 0,
            ];

            $importedAmount = 0;
            $outcomes = [];

            // Fase 1: transaksi keuangan Photomate.
            foreach ($analysis['rows'] as $index => $row) {
                $result = $row['result'];
                $reason = $row['reason'];
                $transactionId = null;

                if ($result === LuminashImportRow::RESULT_IMPORTED) {
                    try {
                        $transaction = FinancialTransaction::create([
                            'transaction_type' => FinancialTransaction::TYPE_INCOME,
                            'amount' => (int) $row['amount'],
                            'category_id' => $category->id,
                            'account_id' => $account->id,
                            'cabang_id' => $cabang->cabang_id,
                            'payment_method_id' => $row['payment_method_id'],
                            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                            'transaction_date' => $row['transaction_at']->toDateString(),
                            'period_year' => (int) $row['transaction_at']->year,
                            'period_month' => (int) $row['transaction_at']->month,
                            'description' => $this->buildDescription($row),
                            'source_type' => self::SOURCE,
                            'source_id' => (string) $row['order_id'],
                            'created_by' => $user->karyawan_id,
                        ]);

                        $transactionId = $transaction->id;
                        $importedAmount += (int) $row['amount'];
                    } catch (QueryException $exception) {
                        if (! $this->isUniqueViolation($exception)) {
                            throw $exception;
                        }

                        $result = LuminashImportRow::RESULT_DUPLICATE;
                        $reason = 'Order ID sudah pernah diimpor (terdeteksi saat penyimpanan).';
                    }
                }

                $outcomes[$index] = [
                    'result' => $result,
                    'reason' => $reason,
                    'transaction_id' => $transactionId,
                ];

                $counts[$result]++;
            }

            // Fase 2: rekap sesi Golio — satu rekap per tanggal, langsung diajukan ke supervisor.
            $indexesByDate = [];

            foreach ($analysis['rows'] as $index => $row) {
                if ($outcomes[$index]['result'] !== LuminashImportRow::RESULT_IMPORTED) {
                    continue;
                }

                $indexesByDate[$row['transaction_at']->toDateString()][] = $index;
            }

            ksort($indexesByDate);

            $reportIdsByDate = [];

            foreach ($indexesByDate as $date => $indexes) {
                $report = SessionReport::query()
                    ->where('cabang_id', $cabang->cabang_id)
                    ->whereDate('report_date', $date)
                    ->whereIn('status', [
                        SessionReport::STATUS_DRAFT,
                        SessionReport::STATUS_REVISION,
                        SessionReport::STATUS_WAITING_APPROVAL,
                    ])
                    ->latest('id')
                    ->first();

                if ($report === null) {
                    // Rekap yang sudah APPROVED bersifat immutable, sehingga baris baru
                    // untuk tanggal tersebut dicatat pada rekap baru.
                    $hasFinalReport = SessionReport::query()
                        ->where('cabang_id', $cabang->cabang_id)
                        ->whereDate('report_date', $date)
                        ->where('status', SessionReport::STATUS_APPROVED)
                        ->exists();

                    $report = SessionReport::create([
                        'report_date' => $date,
                        'cabang_id' => $cabang->cabang_id,
                        'status' => SessionReport::STATUS_DRAFT,
                        'event_note' => $hasFinalReport
                            ? 'Tambahan impor Luminash CSV setelah rekap final: ' . $fileName
                            : 'Impor Luminash CSV: ' . $fileName,
                        'submitted_by' => $user->karyawan_id,
                    ]);
                }

                foreach ($indexes as $index) {
                    $row = $analysis['rows'][$index];
                    $amount = (int) $row['amount'];

                    SessionTransaction::create([
                        'session_report_id' => $report->id,
                        'payment_method' => $row['session_payment_method'],
                        'session_count' => max(1, app(PricingService::class)->calculateSessionsFromPrice($amount)),
                        'amount' => $amount,
                        'notes' => $this->buildSessionNote($row),
                    ]);
                }

                if ($crewIds !== []) {
                    $report->crews()->syncWithoutDetaching($crewIds);
                }

                app(PricingService::class)->recalculateReport($report);

                // Rekap Golio bersumber penuh dari CSV Luminash, sehingga langsung final
                // tanpa antrean approval Supervisor.
                $report->forceFill([
                    'status' => SessionReport::STATUS_APPROVED,
                    'approved_at' => now(),
                ])->saveQuietly();

                ApprovalLog::create([
                    'session_report_id' => $report->id,
                    'user_id' => $user->karyawan_id,
                    'action' => 'APPROVED',
                    'note' => 'Disetujui otomatis dari impor Luminash CSV: ' . $fileName,
                    'created_at' => now(),
                ]);

                $reportIdsByDate[$date] = $report->id;
            }

            // Fase 3: catat riwayat setiap baris (tautan ke transaksi keuangan & rekap sesi).
            foreach ($analysis['rows'] as $index => $row) {
                LuminashImportRow::create([
                    'batch_id' => $batch->id,
                    'order_id' => (string) $row['order_id'],
                    'transaction_at' => $row['transaction_at'],
                    'booth_name' => $row['booth_name'],
                    'payment_method' => $row['payment_method'],
                    'amount' => $row['amount'],
                    'print_count' => $row['print_count'],
                    'extra_prints' => $row['extra_prints'],
                    'source_status' => $row['status_raw'],
                    'frame_name' => $row['frame_name'],
                    'voucher_code' => $row['voucher_code'],
                    'result' => $outcomes[$index]['result'],
                    'reason' => $outcomes[$index]['reason'],
                    'financial_transaction_id' => $outcomes[$index]['transaction_id'],
                    'session_report_id' => $row['transaction_at'] !== null
                        ? ($reportIdsByDate[$row['transaction_at']->toDateString()] ?? null)
                        : null,
                    'raw_payload' => $row['raw_payload'],
                ]);
            }

            // Fase 4: satukan transaksi Luminash Golio (Pendapatan Booth) menjadi
            // satu rekap per tanggal agar laporan menampilkan rekap harian.
            $importedDates = [];

            foreach ($outcomes as $index => $outcome) {
                if ($outcome['result'] !== LuminashImportRow::RESULT_IMPORTED) {
                    continue;
                }

                $transactionAt = $analysis['rows'][$index]['transaction_at'] ?? null;

                if ($transactionAt !== null) {
                    $importedDates[$transactionAt->toDateString()] = true;
                }
            }

            app(LuminashRekapService::class)->consolidateDates(array_keys($importedDates), $user);

            $batch->update([
                'imported_count' => $counts[LuminashImportRow::RESULT_IMPORTED],
                'excluded_incomplete_count' => $counts[LuminashImportRow::RESULT_EXCLUDED_INCOMPLETE],
                'excluded_unknown_status_count' => $counts[LuminashImportRow::RESULT_EXCLUDED_UNKNOWN_STATUS],
                'excluded_voucher_count' => $counts[LuminashImportRow::RESULT_EXCLUDED_VOUCHER],
                'duplicate_count' => $counts[LuminashImportRow::RESULT_DUPLICATE],
                'invalid_count' => $counts[LuminashImportRow::RESULT_INVALID],
                'session_report_count' => count($reportIdsByDate),
                'total_imported_amount' => $importedAmount,
            ]);

            return $batch->refresh();
        });
    }

    private function buildSessionNote(array $row): string
    {
        $parts = array_filter([
            'Luminash',
            'Order ' . $row['order_id'],
            filled($row['frame_name']) ? 'Frame ' . $row['frame_name'] : null,
            $row['print_count'] !== null ? $row['print_count'] . ' cetak' : null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertDatesOpenForUser(array $rows, Karyawan $user): void
    {
        $workflow = app(SessionWorkflowService::class);
        $closedDates = [];

        foreach ($rows as $row) {
            if (($row['result'] ?? null) !== LuminashImportRow::RESULT_IMPORTED || $row['transaction_at'] === null) {
                continue;
            }

            $date = $row['transaction_at']->toDateString();

            if ($workflow->isInputClosed($user, $date)) {
                $closedDates[$date] = true;
            }
        }

        if ($closedDates !== []) {
            throw new RuntimeException(
                'Impor memuat tanggal di luar batas input crew: ' . implode(', ', array_keys($closedDates))
                . '. Batas input crew adalah 2 hari setelah tanggal sesi — minta supervisor/admin menjalankan impor ini.'
            );
        }
    }

    private function buildDescription(array $row): string
    {
        $parts = array_filter([
            'Luminash',
            $row['booth_name'],
            filled($row['frame_name']) ? 'Frame ' . $row['frame_name'] : null,
            $row['print_count'] !== null ? $row['print_count'] . ' cetak (+' . ($row['extra_prints'] ?? 0) . ' tambahan)' : null,
            'Order ' . $row['order_id'],
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int, string|null>  $orderIds
     * @return array<int, string>
     */
    private function existingOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter($orderIds, fn ($id) => filled($id))));
        $existing = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $existing = array_merge($existing, FinancialTransaction::withTrashed()
                ->where('source_type', self::SOURCE)
                ->whereIn('source_id', $chunk)
                ->pluck('source_id')
                ->all());

            $existing = array_merge($existing, LuminashImportRow::query()
                ->where('result', LuminashImportRow::RESULT_IMPORTED)
                ->whereIn('order_id', $chunk)
                ->pluck('order_id')
                ->all());
        }

        return array_values(array_unique($existing));
    }

    /**
     * @return array<string, int>
     */
    private function paymentMethodMap(): array
    {
        return FinancialPaymentMethod::query()
            ->active()
            ->get()
            ->mapWithKeys(fn (FinancialPaymentMethod $method): array => [
                $this->normalizeMethodKey($method->name) => $method->id,
            ])
            ->all();
    }

    private function ensureGolioCabang(): Cabang
    {
        $cabang = Cabang::query()->where('nama_cabang', self::CABANG_NAME)->first();

        if ($cabang) {
            return $cabang;
        }

        $perusahaanId = null;
        foreach (['Express Self Run', 'Express Sewa', 'Photomate Express', 'Newspaper Janus'] as $siblingBranch) {
            $perusahaanId ??= Cabang::withTrashed()->where('nama_cabang', $siblingBranch)->value('perusahaan_id');
        }
        $perusahaanId ??= Perusahaan::query()->first()?->perusahaan_id;

        if (! $perusahaanId) {
            throw new RuntimeException('Cabang "' . self::CABANG_NAME . '" belum tersedia dan data perusahaan belum ada. Buat data perusahaan terlebih dahulu.');
        }

        $nextNumber = ((int) Cabang::withTrashed()
            ->pluck('cabang_id')
            ->map(fn ($id) => (int) substr((string) $id, 1))
            ->max()) + 1;

        return Cabang::create([
            'cabang_id' => 'C' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
            'perusahaan_id' => $perusahaanId,
            'nama_cabang' => self::CABANG_NAME,
            'alamat' => 'Golio Booth',
        ]);
    }

    private function hasVoucher(array $row): bool
    {
        if ($this->normalizeVoucherCode($row['voucher_code'] ?? null) !== null) {
            return true;
        }

        foreach ([$row['payment_method'] ?? null, $row['payment_type'] ?? null] as $value) {
            if (filled($value) && $this->normalizeKey($value) === 'voucher') {
                return true;
            }
        }

        return false;
    }

    private function normalizeVoucherCode(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        if (in_array(strtolower($value), ['-', 'n/a', 'na', 'null', 'none', 'tidak ada'], true)) {
            return null;
        }

        return $value;
    }

    private function parseTimestamp(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat(self::TIMESTAMP_FORMAT, trim($raw));
        } catch (\Throwable) {
            // Lanjut ke fallback di bawah.
        }

        try {
            return Carbon::parse(trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseAmount(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1) {
            $normalized = str_replace('.', '', $value);
        } else {
            $normalized = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';

            if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
                return null;
            }

            if (str_contains($normalized, ',')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            }
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    private function parseInteger(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return is_numeric(trim($raw)) ? (int) trim($raw) : null;
    }

    private function normalizeMethodKey(string $value): string
    {
        $key = $this->normalizeKey($value);

        return self::PAYMENT_METHOD_ALIASES[$key] ?? $key;
    }

    /**
     * Metode pembayaran untuk modul rekap sesi (hanya mendukung CASH/QRIS).
     */
    private function sessionPaymentMethod(?string $method): ?string
    {
        return match ($this->normalizeMethodKey((string) $method)) {
            'cash' => 'CASH',
            'qris' => 'QRIS',
            default => null,
        };
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }

    /**
     * @param  array<int, string|false|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (filled($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string|false|null>  $data
     * @return array<string, mixed>
     */
    private function mapRow(array $data, array $mapping, int $line): array
    {
        $value = fn (string $key): ?string => isset($mapping[$key])
            ? (trim((string) ($data[$mapping[$key]] ?? '')) ?: null)
            : null;

        return [
            'line' => $line,
            'order_id' => $value('order_id'),
            'timestamp_raw' => $value('timestamp'),
            'booth_name' => $value('booth_name'),
            'payment_method' => $value('payment_method'),
            'payment_type' => $value('payment_type'),
            'amount_raw' => $value('amount'),
            'print_count_raw' => $value('print_count'),
            'extra_prints_raw' => $value('extra_prints'),
            'status_raw' => $value('status'),
            'frame_name' => $value('frame_name'),
            'voucher_code' => $value('voucher_code'),
            'email' => $value('email'),
            'consent' => $value('consent'),
            'qr_url' => $value('qr_url'),
            'raw_payload' => array_combine(
                array_keys($mapping),
                array_map(fn (int $index) => $data[$index] ?? null, array_values($mapping))
            ) ?: [],
        ];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function columnLabels(array $keys): array
    {
        $labels = [
            'order_id' => 'Order ID',
            'timestamp' => 'Timestamp',
            'amount' => 'Amount',
            'status' => 'Status',
            'voucher_code' => 'Voucher Code',
        ];

        return array_map(fn (string $key): string => $labels[$key] ?? $key, $keys);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint failed');
    }
}
