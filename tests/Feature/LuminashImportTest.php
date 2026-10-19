<?php

namespace Tests\Feature;

use App\Filament\Pages\KeuanganReport;
use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Filament\Resources\SessionReportResource;
use App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport;
use App\Models\ApprovalLog;
use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\LuminashImportBatch;
use App\Models\LuminashImportRow;
use App\Models\Perusahaan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\KeuanganService;
use App\Services\LuminashImportService;
use App\Services\SessionReportFinancialService;
use App\Utils\RupiahHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LuminashImportTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $managerFinance;

    protected FinancialCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->category = FinancialCategory::where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        $this->managerFinance = $this->makeUser('K0001', 'Manager Finance', 'R04', 'manager.finance@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'create_keuangan_transaction',
            'update_keuangan_transaction',
            'delete_keuangan_transaction',
            'view_keuangan_report',
            'view_keuangan_dashboard',
        ]);
    }

    public function test_transaksi_complete_tanpa_voucher_diimpor_ke_cabang_golio(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('luminash.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance, 'luminash.csv');

        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->session_report_count);
        $this->assertSame(25000, $batch->total_imported_amount);
        $this->assertSame('2026-10-11', $batch->period_start->toDateString());
        $this->assertSame('luminash.csv', $batch->file_name);
        $this->assertSame($this->managerFinance->karyawan_id, $batch->imported_by);
        $this->assertSame('Golio', $batch->cabang?->nama_cabang);

        $transaction = FinancialTransaction::withTrashed()
            ->where('source_id', '20261011213012034')
            ->firstOrFail();

        $this->assertSame(FinancialTransaction::TYPE_INCOME, $transaction->transaction_type);
        $this->assertSame(25000, $transaction->amount);
        $this->assertSame('2026-10-11', $transaction->transaction_date->toDateString());
        $this->assertSame(10, $transaction->period_month);
        $this->assertSame(2026, $transaction->period_year);
        $this->assertSame(FinancialTransaction::PAYMENT_STATUS_LUNAS, $transaction->payment_status);
        $this->assertSame('Golio', $transaction->account?->name);
        $this->assertSame('Golio', $transaction->cabang?->nama_cabang);
        $this->assertSame('QRIS', $transaction->paymentMethod?->name);
        $this->assertSame($this->category->id, $transaction->category_id);
        $this->assertSame($this->managerFinance->karyawan_id, $transaction->created_by);
        $this->assertSame($this->managerFinance->karyawan_id, $transaction->submitted_by);
        $this->assertSame($this->managerFinance->nama_lengkap, $transaction->submitted_by_name);
        $this->assertSame(LuminashImportService::SOURCE, $transaction->source_type);
        $this->assertSame('20261011213012034', $transaction->source_id);
        $this->assertNull($transaction->party_id);
        $this->assertStringContainsString('Luminash', (string) $transaction->description);
        $this->assertStringContainsString('20261011213012034', (string) $transaction->description);

        $report = SessionReport::firstOrFail();

        $this->assertSame('2026-10-11', $report->report_date->toDateString());
        $this->assertSame('Golio', $report->cabang?->nama_cabang);
        $this->assertSame(SessionReport::STATUS_APPROVED, $report->status);
        $this->assertNotNull($report->approved_at);
        $this->assertNull($report->approved_by);
        $this->assertSame($this->managerFinance->karyawan_id, $report->submitted_by);
        $this->assertSame(1, $report->total_transactions);
        $this->assertSame(1, $report->total_sessions);
        $this->assertSame(25000, $report->total_qris_amount);
        $this->assertSame(0, $report->total_cash_amount);
        $this->assertSame(25000, $report->grand_total_amount);
        $this->assertStringContainsString('Impor Luminash CSV', (string) $report->event_note);

        $sessionTransaction = SessionTransaction::firstOrFail();

        $this->assertSame('QRIS', $sessionTransaction->payment_method);
        $this->assertSame(25000, $sessionTransaction->amount);
        $this->assertSame(1, $sessionTransaction->session_count);
        $this->assertStringContainsString('20261011213012034', (string) $sessionTransaction->notes);

        $row = LuminashImportRow::firstOrFail();

        $this->assertSame(LuminashImportRow::RESULT_IMPORTED, $row->result);
        $this->assertSame($transaction->id, $row->financial_transaction_id);
        $this->assertSame($report->id, $row->session_report_id);
        $this->assertSame(1, $row->print_count);
        $this->assertSame('Golio 1', $row->frame_name);
    }

    public function test_rekap_sesi_dibuat_per_tanggal_dengan_total_yang_benar(): void
    {
        $this->actingAs($this->managerFinance);

        $crew = $this->makeUser('K0007', 'Crew Golio', 'R07', 'crew.golio@photomate.id', []);

        $path = $this->storeCsv('dua-hari.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete', 'QRIS', '25000.0'),
            $this->csvRow('20261011192203192', '11 Oct 2026 19:22', 'Complete', 'QRIS', '40000.0'),
            $this->csvRow('20261010225503199', '10 Oct 2026 22:55', 'Complete', 'QRIS', '25000.0'),
            $this->csvRow('20261012095810231', '12 Oct 2026 09:58', 'Incomplete'),
            $this->csvRow('20261007130111694', '07 Oct 2026 13:01', 'Complete', 'Voucher', '0.0', 'GJC4MG', 'voucher'),
        ]);

        $batch = app(LuminashImportService::class)->import(
            $path,
            'local',
            $this->managerFinance,
            'dua-hari.csv',
            [$crew->karyawan_id],
        );

        $this->assertSame(3, $batch->imported_count);
        $this->assertSame(2, $batch->session_report_count);
        $this->assertSame(1, $batch->excluded_incomplete_count);
        $this->assertSame(1, $batch->excluded_voucher_count);
        $this->assertSame(90000, $batch->total_imported_amount);

        $this->assertDatabaseCount('session_reports', 2);
        // 3 komponen per-order (soft delete) + 2 baris rekap (satu per tanggal).
        $this->assertDatabaseCount('financial_transactions', 5);
        $this->assertSame(2, FinancialTransaction::count());

        $firstDay = SessionReport::whereDate('report_date', '2026-10-11')->firstOrFail();
        $secondDay = SessionReport::whereDate('report_date', '2026-10-10')->firstOrFail();

        $this->assertSame(SessionReport::STATUS_APPROVED, $firstDay->status);
        $this->assertSame(SessionReport::STATUS_APPROVED, $secondDay->status);

        $this->assertSame(2, $firstDay->total_transactions);
        $this->assertSame(2, $firstDay->total_sessions);
        $this->assertSame(65000, $firstDay->total_qris_amount);
        $this->assertSame(65000, $firstDay->grand_total_amount);

        $this->assertSame(1, $secondDay->total_transactions);
        $this->assertSame(1, $secondDay->total_sessions);
        $this->assertSame(25000, $secondDay->grand_total_amount);

        $this->assertTrue($firstDay->crews->contains('karyawan_id', $crew->karyawan_id));
        $this->assertTrue($secondDay->crews->contains('karyawan_id', $crew->karyawan_id));

        $this->assertSame(2, $firstDay->transactions()->count());
        $this->assertSame(
            3,
            LuminashImportRow::whereNotNull('session_report_id')->count()
        );
    }

    public function test_crew_rekap_mengikuti_form_dan_boleh_kosong(): void
    {
        $this->actingAs($this->managerFinance);

        $crew = $this->makeUser('K0007', 'Crew Golio', 'R07', 'crew.golio@photomate.id', []);

        $path = $this->storeCsv('dengan-crew.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance, null, [$crew->karyawan_id]);

        $this->assertTrue(SessionReport::firstOrFail()->crews->contains('karyawan_id', $crew->karyawan_id));

        $path = $this->storeCsv('tanpa-crew.csv', [
            $this->csvRow('20261010225503199', '10 Oct 2026 22:55', 'Complete'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $report = SessionReport::whereDate('report_date', '2026-10-10')->firstOrFail();

        $this->assertSame(0, $report->crews()->count());
        $this->assertSame('HAS_ISSUE', $report->validation_status);
        $this->assertContains('Rekap sesi wajib memiliki minimal satu crew.', $report->validation_issues);
    }

    public function test_crew_tidak_dapat_mengimpor_tanggal_di_luar_batas_input(): void
    {
        $crew = $this->makeUser('K0007', 'Crew Golio', 'R07', 'crew.golio@photomate.id', []);

        $path = $this->storeCsv('bulan-lalu.csv', [
            $this->csvRow('20260912231300266', '12 Sep 2026 23:13', 'Complete'),
        ]);

        $this->actingAs($crew);

        try {
            app(LuminashImportService::class)->import($path, 'local', $crew);

            $this->fail('Impor oleh crew untuk tanggal lama seharusnya ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('di luar batas input crew', $exception->getMessage());
        }

        $this->assertDatabaseCount('luminash_import_batches', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('session_reports', 0);

        $this->actingAs($this->managerFinance);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(1, $batch->session_report_count);
    }

    public function test_transaksi_complete_dengan_voucher_dikecualikan(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('voucher.csv', [
            $this->csvRow('20261007130111694', '07 Oct 2026 13:01', 'Complete', 'Voucher', '0.0', 'GJC4MG', 'voucher'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertSame(0, $batch->imported_count);
        $this->assertSame(1, $batch->excluded_voucher_count);
        $this->assertSame(0, $batch->session_report_count);
        $this->assertSame(0, $batch->total_imported_amount);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('session_reports', 0);
        $this->assertSame(LuminashImportRow::RESULT_EXCLUDED_VOUCHER, LuminashImportRow::firstOrFail()->result);
    }

    public function test_transaksi_incomplete_tanpa_voucher_dikecualikan(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('incomplete.csv', [
            $this->csvRow('20261012095810231', '12 Oct 2026 09:58', 'Incomplete'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertSame(0, $batch->imported_count);
        $this->assertSame(1, $batch->excluded_incomplete_count);
        $this->assertSame(0, $batch->session_report_count);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_transaksi_incomplete_dengan_voucher_dikecualikan_sebagai_incomplete(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('incomplete-voucher.csv', [
            $this->csvRow('20261010100033223', '10 Oct 2026 10:00', 'Incomplete', 'Voucher', '0.0', 'ABC123', 'voucher'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertSame(0, $batch->imported_count);
        $this->assertSame(1, $batch->excluded_incomplete_count);
        $this->assertSame(0, $batch->excluded_voucher_count);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_baris_tidak_valid_dicatat_dengan_alasan_dan_tidak_diimpor(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('invalid.csv', [
            $this->csvRow('20261011213012034', 'bukan tanggal', 'Complete'),
            $this->csvRow('20261011213012035', '11 Oct 2026 21:30', 'Complete', 'QRIS', 'abc'),
            $this->csvRow('', '11 Oct 2026 21:30', 'Complete'),
            $this->csvRow('20261011213012037', '11 Oct 2026 21:30', 'Complete', 'Crypto', '50000.0', '-', 'crypto'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertSame(4, $batch->invalid_count);
        $this->assertSame(0, $batch->imported_count);
        $this->assertSame(0, $batch->session_report_count);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('session_reports', 0);

        $this->assertStringContainsString('Format tanggal', $this->reasonFor('20261011213012034'));
        $this->assertStringContainsString('Nominal', $this->reasonFor('20261011213012035'));
        $this->assertStringContainsString('Order ID kosong', $this->reasonFor(''));
        $this->assertStringContainsString('belum dipetakan', $this->reasonFor('20261011213012037'));
    }

    public function test_urutan_kolom_tidak_mempengaruhi_hasil_impor(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv(
            'shuffled.csv',
            [
                ['25000.0', 'Complete', '20261011213012034', 'BOOTH_1', 'QRIS', '-', '1', '0', 'qris', 'Golio 1', '', 'No', 'https://media.luminash.id/session/abc', '11 Oct 2026 21:30'],
            ],
            ['Amount', 'Status', 'Order ID', 'Booth Name', 'Payment Method', 'Voucher Code', 'Print Count', 'Extra Prints', 'Payment Type', 'Frame Name', 'Email', 'Consent', 'QR URL', 'Timestamp'],
        );

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertSame(1, $batch->imported_count);

        $transaction = FinancialTransaction::withTrashed()
            ->where('source_id', '20261011213012034')
            ->firstOrFail();
        $this->assertSame(25000, $transaction->amount);
        $this->assertSame('2026-10-11', $transaction->transaction_date->toDateString());
        $this->assertSame('20261011213012034', $transaction->source_id);
        $this->assertSame('QRIS', $transaction->paymentMethod?->name);

        $this->assertSame('QRIS', SessionTransaction::firstOrFail()->payment_method);
    }

    public function test_kolom_voucher_hilang_menolak_file(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv(
            'tanpa-voucher.csv',
            [['20261011213012034', '11 Oct 2026 21:30', 'BOOTH_1', 'QRIS', 'qris', '25000.0', '1', '0', 'Complete']],
            ['Order ID', 'Timestamp', 'Booth Name', 'Payment Method', 'Payment Type', 'Amount', 'Print Count', 'Extra Prints', 'Status'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voucher Code');

        app(LuminashImportService::class)->analyzeFile($path, 'local');
    }

    public function test_upload_ulang_file_yang_sama_tidak_membuat_transaksi_ganda(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('ulang.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
            $this->csvRow('20261011211634223', '11 Oct 2026 21:16', 'Complete'),
        ]);

        $service = app(LuminashImportService::class);

        $first = $service->import($path, 'local', $this->managerFinance);
        $second = $service->import($path, 'local', $this->managerFinance);

        $this->assertSame(2, $first->imported_count);
        $this->assertSame(1, $first->session_report_count);
        $this->assertSame(0, $second->imported_count);
        $this->assertSame(0, $second->session_report_count);
        $this->assertSame(2, $second->duplicate_count);
        $this->assertSame(0, $second->total_imported_amount);
        // 2 komponen per-order (soft delete) + 1 baris rekap.
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('session_reports', 1);
    }

    public function test_file_campuran_hanya_mengimpor_transaksi_baru(): void
    {
        $this->actingAs($this->managerFinance);

        $service = app(LuminashImportService::class);

        $first = $this->storeCsv('bagian-1.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
        ]);

        $second = $this->storeCsv('bagian-2.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
            $this->csvRow('20261011192203192', '11 Oct 2026 19:22', 'Complete'),
        ]);

        $service->import($first, 'local', $this->managerFinance);
        $batch = $service->import($second, 'local', $this->managerFinance);

        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(1, $batch->duplicate_count);
        $this->assertSame(1, $batch->session_report_count);
        $this->assertSame(25000, $batch->total_imported_amount);
        // 2 komponen per-order (soft delete) + 1 baris rekap untuk tanggal yang sama.
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('session_reports', 2);

        // Rekap tanggal tersebut sudah final (immutable), sehingga baris baru dicatat
        // pada rekap tambahan untuk tanggal yang sama.
        $tambahan = SessionReport::where('event_note', 'like', 'Tambahan impor Luminash CSV%')->firstOrFail();

        $this->assertSame('2026-10-11', $tambahan->report_date->toDateString());
        $this->assertSame(SessionReport::STATUS_APPROVED, $tambahan->status);
        $this->assertSame(1, $tambahan->transactions()->count());
    }

    public function test_csv_contoh_luminash_diimpor_dengan_filter_yang_benar(): void
    {
        $this->actingAs($this->managerFinance);

        $samplePath = base_path('sample data transaksi/transactions_2026-09-12_to_2026-10-12.csv');
        $this->assertFileExists($samplePath);

        $path = 'luminash-imports/sample.csv';
        Storage::disk('local')->put($path, file_get_contents($samplePath));

        $expected = $this->expectedTotalsFromCsv($samplePath);

        $analysis = app(LuminashImportService::class)->analyzeFile($path, 'local');

        $this->assertSame(306, $analysis['summary']['total_rows']);
        $this->assertSame(5, $analysis['summary']['excluded_incomplete']);
        $this->assertSame(6, $analysis['summary']['excluded_voucher']);
        $this->assertSame(0, $analysis['summary']['excluded_unknown_status']);
        $this->assertSame(0, $analysis['summary']['duplicate']);
        $this->assertSame(0, $analysis['summary']['invalid']);
        $this->assertSame($expected['count'], $analysis['summary']['imported']);
        $this->assertSame($expected['total'], $analysis['summary']['total_amount']);
        $this->assertSame('2026-09-12', $analysis['summary']['period_start']);
        $this->assertSame('2026-10-12', $analysis['summary']['period_end']);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance, 'sample.csv');

        $this->assertSame($expected['count'], $batch->imported_count);
        $this->assertSame($expected['dates'], $batch->session_report_count);
        $this->assertSame($expected['total'], $batch->total_imported_amount);
        // Komponen per-order (soft delete) + satu baris rekap per tanggal.
        $this->assertDatabaseCount('financial_transactions', $expected['count'] + $expected['dates']);
        $this->assertDatabaseCount('session_reports', $expected['dates']);

        $golio = Cabang::where('nama_cabang', 'Golio')->firstOrFail();

        $this->assertSame(
            $expected['dates'],
            FinancialTransaction::where('cabang_id', $golio->cabang_id)->count()
        );

        $this->assertSame($expected['total'], (int) SessionReport::sum('grand_total_amount'));
        $this->assertSame($expected['count'], (int) SessionReport::sum('total_transactions'));

        $summary = app(KeuanganService::class)->summary(
            \Illuminate\Support\Carbon::parse('2026-09-01'),
            \Illuminate\Support\Carbon::parse('2026-10-31'),
            $golio->cabang_id,
        );

        $this->assertSame($expected['total'], $summary['income']);
        $this->assertSame(0, $summary['expense']);
        $this->assertSame($expected['total'], $summary['balance']);

        Livewire::test(KeuanganReport::class)
            ->set('data.periode', KeuanganService::PRESET_CUSTOM)
            ->set('data.dari', '2026-09-01')
            ->set('data.sampai', '2026-10-31')
            ->set('data.cabang_id', $golio->cabang_id)
            ->assertSee(RupiahHelper::format($expected['total']));

        Livewire::test(ListFinancialTransactions::class)
            ->assertSee('Luminash CSV');
    }

    public function test_panel_impor_muncul_hanya_untuk_cabang_golio(): void
    {
        $this->actingAs($this->managerFinance);

        $golioId = Cabang::where('nama_cabang', 'Golio')->value('cabang_id');

        Livewire::test(CreateSessionReport::class)
            ->assertDontSee('Impor ini hanya berlaku untuk cabang Golio.')
            ->set('data.cabang_id', 'C9999')
            ->assertDontSee('Impor ini hanya berlaku untuk cabang Golio.')
            ->set('data.cabang_id', $golioId)
            ->assertSee('Impor ini hanya berlaku untuk cabang Golio.');

        $this->get('/admin/keuangan-import-luminash')->assertNotFound();
    }

    public function test_halaman_buat_rekap_sesi_menampilkan_riwayat_dan_alur_impor(): void
    {
        $this->actingAs($this->managerFinance);

        $crew = $this->makeUser('K0007', 'Crew Golio', 'R07', 'crew.golio@photomate.id', []);

        $content = $this->csvContent([
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
            $this->csvRow('20261011211634223', '11 Oct 2026 21:16', 'Complete'),
            $this->csvRow('20261012095810231', '12 Oct 2026 09:58', 'Incomplete'),
            $this->csvRow('20261007130111694', '07 Oct 2026 13:01', 'Complete', 'Voucher', '0.0', 'GJC4MG', 'voucher'),
        ]);

        $this->get(SessionReportResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Buat Rekap Sesi Baru');

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', Cabang::where('nama_cabang', 'Golio')->value('cabang_id'))
            ->set('data.crews', [$crew->karyawan_id])
            ->set('luminashUpload', UploadedFile::fake()->createWithContent('halaman.csv', $content))
            ->assertSee('Impor ini hanya berlaku untuk cabang Golio.')
            ->assertSee('halaman.csv')
            ->assertSee('File dipilih — siap divalidasi')
            ->assertDontSee('Buat Rekap Golio')
            ->call('validasiLuminash')
            ->assertSet('luminashFileState', 'validated')
            ->assertSet('luminashPreviewSummary.imported', 2)
            ->assertSet('luminashPreviewSummary.excluded_incomplete', 1)
            ->assertSet('luminashPreviewSummary.excluded_voucher', 1)
            ->assertSet('luminashPreviewSummary.total_amount', 50000)
            ->assertSee('Validasi berhasil')
            ->assertSee('Lolos impor')
            ->assertSee('Buat Rekap Golio')
            ->call('importLuminash')
            ->assertSet('luminashFileState', 'imported')
            ->assertSee('Impor berhasil')
            ->assertNotified();

        // 2 komponen per-order (soft delete) + 1 baris rekap.
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('session_reports', 1);

        $batch = LuminashImportBatch::firstOrFail();

        $this->assertSame(50000, $batch->total_imported_amount);
        $this->assertSame(1, $batch->session_report_count);
        $this->assertSame('halaman.csv', $batch->file_name);

        $this->assertTrue(SessionReport::firstOrFail()->crews->contains('karyawan_id', $crew->karyawan_id));

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', Cabang::where('nama_cabang', 'Golio')->value('cabang_id'))
            ->assertCanSeeTableRecords([$batch])
            ->assertSee('halaman.csv');
    }

    public function test_file_yang_dipilih_langsung_tampil_di_kartu_dengan_status_siap_divalidasi(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', LuminashImportService::golioCabangId())
            ->set('luminashUpload', UploadedFile::fake()->createWithContent('transaksi-luminash.csv', $this->csvContent([
                $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
            ])))
            ->assertSet('luminashFileState', 'selected')
            ->assertSee('transaksi-luminash.csv')
            ->assertSee('File dipilih — siap divalidasi')
            ->assertSee('KB')
            ->assertDontSee('Validasi berhasil')
            ->assertDontSee('Buat Rekap Golio');
    }

    public function test_hapus_file_mereset_validasi_preview_dan_status(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', LuminashImportService::golioCabangId())
            ->set('luminashUpload', UploadedFile::fake()->createWithContent('hapus.csv', $this->csvContent([
                $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
            ])))
            ->call('validasiLuminash')
            ->assertSet('luminashFileState', 'validated')
            ->assertSet('luminashPreviewSummary.imported', 1)
            ->call('hapusFileLuminash')
            ->assertSet('luminashFileState', 'idle')
            ->assertSet('luminashStoredPath', null)
            ->assertSet('luminashFileName', null)
            ->assertSet('luminashPreviewSummary', null)
            ->assertSet('luminashPreviewRows', null)
            ->assertDontSee('hapus.csv')
            ->assertDontSee('Validasi berhasil')
            ->assertDontSee('Buat Rekap Golio');
    }

    public function test_mengganti_file_mereset_hasil_validasi_lama(): void
    {
        $this->actingAs($this->managerFinance);

        $component = Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', LuminashImportService::golioCabangId())
            ->set('luminashUpload', UploadedFile::fake()->createWithContent('file-a.csv', $this->csvContent([
                $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
                $this->csvRow('20261011211634223', '11 Oct 2026 21:16', 'Complete'),
            ])))
            ->call('validasiLuminash')
            ->assertSet('luminashPreviewSummary.imported', 2);

        $component
            ->set('luminashUpload', UploadedFile::fake()->createWithContent('file-b.csv', $this->csvContent([
                $this->csvRow('20261010225503199', '10 Oct 2026 22:55', 'Complete'),
            ])))
            ->assertSet('luminashFileState', 'selected')
            ->assertSet('luminashPreviewSummary', null)
            ->assertSet('luminashPreviewRows', null)
            ->assertSee('file-b.csv')
            ->call('validasiLuminash')
            ->assertSet('luminashPreviewSummary.imported', 1)
            ->call('importLuminash')
            ->assertNotified();

        $this->assertSame(1, FinancialTransaction::count());

        $rekap = FinancialTransaction::firstOrFail();
        $this->assertSame('REKAP-2026-10-10', $rekap->source_id);
        $this->assertSame($this->managerFinance->karyawan_id, $rekap->submitted_by);
        $this->assertSame($this->managerFinance->nama_lengkap, $rekap->submitted_by_name);
        $this->assertTrue(FinancialTransaction::withTrashed()->where('source_id', '20261010225503199')->exists());
        $this->assertSame('file-b.csv', LuminashImportBatch::firstOrFail()->file_name);
    }

    public function test_validasi_gagal_menampilkan_alasan_dan_tidak_menampilkan_preview(): void
    {
        $this->actingAs($this->managerFinance);

        $content = $this->csvContent(
            [['20261011213012034', '11 Oct 2026 21:30', 'BOOTH_1', 'QRIS', 'qris', '25000.0', '1', '0', 'Complete']],
            ['Order ID', 'Timestamp', 'Booth Name', 'Payment Method', 'Payment Type', 'Amount', 'Print Count', 'Extra Prints', 'Status'],
        );

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', LuminashImportService::golioCabangId())
            ->set('luminashUpload', UploadedFile::fake()->createWithContent('tanpa-voucher.csv', $content))
            ->call('validasiLuminash')
            ->assertSet('luminashFileState', 'invalid')
            ->assertSet('luminashPreviewSummary', null)
            ->assertSee('Validasi gagal')
            ->assertSee('Voucher Code')
            ->assertDontSee('Buat Rekap Golio');

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_halaman_buat_rekap_sesi_menolak_user_tanpa_permission_import(): void
    {
        $staff = $this->makeUser('K0005', 'Staff HRD', 'R02', 'staff.hrd@photomate.id', ['view_any_karyawan']);

        $this->actingAs($staff);

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', Cabang::where('nama_cabang', 'Golio')->value('cabang_id'))
            ->assertDontSee('Impor ini hanya berlaku untuk cabang Golio.');

        Livewire::test(CreateSessionReport::class)
            ->call('importLuminash')
            ->assertForbidden();

        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_rekap_golio_langsung_final_tanpa_approval_dan_tanpa_notifikasi(): void
    {
        Notification::fake();

        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('final.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->managerFinance, 'final.csv');

        $report = SessionReport::firstOrFail();

        $this->assertSame(SessionReport::STATUS_APPROVED, $report->status);
        $this->assertNotNull($report->approved_at);
        $this->assertNull($report->approved_by);
        $this->assertSame(0, SessionReport::where('status', SessionReport::STATUS_WAITING_APPROVAL)->count());

        Notification::assertNothingSent();

        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $report->id,
            'user_id' => $this->managerFinance->karyawan_id,
            'action' => 'APPROVED',
        ]);

        $this->assertStringContainsString('Luminash CSV', (string) ApprovalLog::firstOrFail()->note);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(1, $batch->session_report_count);
    }

    public function test_form_golio_menyembunyikan_input_manual_dan_tombol_approval(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', LuminashImportService::golioCabangId())
            ->assertDontSee('Detail Sesi Foto')
            ->assertDontSee('Tambah Sesi')
            ->assertDontSee('Upload Evidence')
            ->assertDontSee('Ringkasan Kalkulasi Otomatis')
            ->assertDontSee('Kirim ke Supervisor')
            ->assertDontSee('Tanggal Sesi')
            ->assertSee('Buat Rekap Sesi Baru');

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', 'C9999')
            ->assertSee('Detail Sesi Foto')
            ->assertSee('Tambah Sesi')
            ->assertSee('Upload Evidence')
            ->assertSee('Ringkasan Kalkulasi Otomatis')
            ->assertSee('Kirim ke Supervisor');
    }

    public function test_backend_menolak_pembuatan_manual_rekap_golio(): void
    {
        $this->actingAs($this->managerFinance);

        $crew = $this->makeUser('K0007', 'Crew Golio', 'R07', 'crew.golio@photomate.id', []);

        Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', LuminashImportService::golioCabangId())
            ->set('data.crews', [$crew->karyawan_id])
            ->call('create')
            ->assertHasFormErrors(['cabang_id']);

        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_csv_kosong_menolak_file(): void
    {
        $this->actingAs($this->managerFinance);

        $path = 'luminash-imports/kosong.csv';
        Storage::disk('local')->put($path, "Order ID,Timestamp,Booth Name,Payment Method,Payment Type,Amount,Print Count,Extra Prints,Status,Frame Name,Voucher Code,Email,Consent,QR URL\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak berisi data');

        app(LuminashImportService::class)->analyzeFile($path, 'local');
    }

    public function test_header_csv_tidak_dikenali_menolak_file(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('header-salah.csv', [['a', 'b', 'c']], ['Foo', 'Bar', 'Baz']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Header CSV tidak dikenali');

        app(LuminashImportService::class)->analyzeFile($path, 'local');
    }

    public function test_kegagalan_penyimpanan_membatalkan_seluruh_impor(): void
    {
        $this->actingAs($this->managerFinance);

        Event::listen('eloquent.creating: ' . SessionTransaction::class, function (): void {
            throw new RuntimeException('simulasi kegagalan penyimpanan');
        });

        $path = $this->storeCsv('gagal.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete'),
            $this->csvRow('20261011211634223', '11 Oct 2026 21:16', 'Complete'),
        ]);

        try {
            app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

            $this->fail('Impor seharusnya gagal dan dibatalkan seluruhnya.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('simulasi kegagalan', $exception->getMessage());
        }

        $this->assertDatabaseCount('luminash_import_batches', 0);
        $this->assertDatabaseCount('luminash_import_rows', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_audit_logs', 0);
        $this->assertDatabaseCount('session_reports', 0);
        $this->assertDatabaseCount('session_transactions', 0);
    }

    public function test_ringkasan_menampilkan_rincian_metode_dan_total_cetakan(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('ringkasan.csv', [
            $this->csvRow('20261011213012034', '11 Oct 2026 21:30', 'Complete', 'QRIS', '25000.0'),
            $this->csvRow('20261011211634223', '11 Oct 2026 21:16', 'Complete', 'QRIS', '40000.0'),
            $this->csvRow('20261011192203192', '11 Oct 2026 19:22', 'Complete', 'Cash', '50000.0', '-', 'cash'),
            $this->csvRow('20261012095810231', '12 Oct 2026 09:58', 'Incomplete'),
        ]);

        $analysis = app(LuminashImportService::class)->analyzeFile($path, 'local');

        $this->assertSame(3, $analysis['summary']['imported']);
        $this->assertSame(2, $analysis['summary']['by_payment_method']['QRIS']['count']);
        $this->assertSame(65000, $analysis['summary']['by_payment_method']['QRIS']['amount']);
        $this->assertSame(1, $analysis['summary']['by_payment_method']['CASH']['count']);
        $this->assertSame(50000, $analysis['summary']['by_payment_method']['CASH']['amount']);
        $this->assertSame(3, $analysis['summary']['total_prints']);
        $this->assertSame(0, $analysis['summary']['total_extra_prints']);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>|null  $headers
     */
    protected function csvContent(array $rows, ?array $headers = null): string
    {
        $headers ??= [
            'Order ID', 'Timestamp', 'Booth Name', 'Payment Method', 'Payment Type', 'Amount',
            'Print Count', 'Extra Prints', 'Status', 'Frame Name', 'Voucher Code', 'Email', 'Consent', 'QR URL',
        ];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>|null  $headers
     */
    protected function storeCsv(string $name, array $rows, ?array $headers = null): string
    {
        $path = 'luminash-imports/' . $name;
        Storage::disk('local')->put($path, $this->csvContent($rows, $headers));

        return $path;
    }

    /**
     * @return array<int, string>
     */
    protected function csvRow(
        string $orderId,
        string $timestamp,
        string $status,
        string $paymentMethod = 'QRIS',
        string $amount = '25000.0',
        string $voucherCode = '-',
        string $paymentType = 'qris',
    ): array {
        return [
            $orderId, $timestamp, 'BOOTH_1', $paymentMethod, $paymentType, $amount,
            '1', '0', $status, 'Golio 1', $voucherCode, '', 'No',
            'https://media.luminash.id/session/' . ($orderId !== '' ? $orderId : 'tanpa-order'),
        ];
    }

    /**
     * Hitung ekspektasi dari file CSV contoh secara independen (tanpa service).
     *
     * @return array{count: int, total: int, dates: int}
     */
    protected function expectedTotalsFromCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        $keys = array_flip($headers);

        $count = 0;
        $total = 0;
        $dates = [];

        while (($data = fgetcsv($handle)) !== false) {
            $status = trim((string) ($data[$keys['Status']] ?? ''));
            $voucher = trim((string) ($data[$keys['Voucher Code']] ?? ''));
            $amount = (float) ($data[$keys['Amount']] ?? 0);

            if (strtolower($status) !== 'complete') {
                continue;
            }

            if ($voucher !== '' && $voucher !== '-') {
                continue;
            }

            $count++;
            $total += (int) round($amount);
            $dates[\Illuminate\Support\Carbon::createFromFormat('d M Y H:i', trim((string) $data[$keys['Timestamp']]))->toDateString()] = true;
        }

        fclose($handle);

        return ['count' => $count, 'total' => $total, 'dates' => count($dates)];
    }

    protected function reasonFor(string $orderId): string
    {
        return (string) LuminashImportRow::query()->where('order_id', $orderId)->value('reason');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function makeUser(
        string $karyawanId,
        string $roleName,
        string $roleId,
        string $email,
        array $permissions
    ): Karyawan {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['role_id' => $roleId]);
        $role->syncPermissions($permissions);

        $user = Karyawan::create([
            'karyawan_id' => $karyawanId,
            'role_id' => $roleId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($karyawanId), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $roleName . ' Uji',
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
