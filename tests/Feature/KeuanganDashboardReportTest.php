<?php

namespace Tests\Feature;

use App\Filament\Pages\KeuanganDashboard;
use App\Filament\Pages\KeuanganReport;
use App\Filament\Widgets\Keuangan\IncomeExpenseChart;
use App\Filament\Widgets\Keuangan\KeuanganStatsOverview;
use App\Filament\Widgets\Keuangan\LatestTransactionsWidget;
use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Permission;
use App\Models\Role;
use App\Services\KeuanganService;
use App\Utils\MonthHelper;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KeuanganDashboardReportTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $financeUser;

    protected FinancialCategory $incomeCategory;

    protected FinancialCategory $tintaCategory;

    protected FinancialCategory $bensinCategory;

    protected FinancialPaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->incomeCategory = FinancialCategory::where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->tintaCategory = FinancialCategory::where('name', 'Tinta')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->bensinCategory = FinancialCategory::where('name', 'Bensin')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->paymentMethod = FinancialPaymentMethod::where('name', 'Transfer')->firstOrFail();

        $this->financeUser = $this->makeUser('K0001', 'Manager Finance', 'R04', 'manager.finance@photomate.id', [
            'view_keuangan_dashboard',
            'view_keuangan_report',
            'export_keuangan_report',
            'print_keuangan_report',
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'create_keuangan_transaction',
            'update_keuangan_transaction',
            'delete_keuangan_transaction',
        ]);
    }

    public function test_dashboard_menampilkan_ringkasan_sesuai_contoh_spesifikasi(): void
    {
        $this->seedContohTransaksi();

        $this->actingAs($this->financeUser)
            ->get('/admin/keuangan')
            ->assertOk()
            ->assertSee('Total Pemasukan')
            ->assertSee('Total Pengeluaran')
            ->assertSee('Saldo')
            ->assertSee('Rp 2.000.000')
            ->assertSee('Rp 481.550')
            ->assertSee('Rp 1.518.450')
            ->assertSee('Transaksi Terbaru')
            ->assertSee('Pemasukan vs Pengeluaran')
            ->assertSee('Pelunasan Event A')
            ->assertSee('Newspaper Janus');
    }

    public function test_dashboard_dan_halaman_laporan_menolak_user_tanpa_permission(): void
    {
        $staff = $this->makeUser('K0002', 'Staff HRD', 'R02', 'staff.hrd@photomate.id', ['view_any_karyawan']);

        $this->actingAs($staff)->get('/admin/keuangan')->assertForbidden();
        $this->actingAs($staff)->get('/admin/keuangan-laporan')->assertForbidden();

        $this->assertFalse(KeuanganDashboard::canAccess());
        $this->assertFalse(KeuanganReport::canAccess());
        $this->assertFalse(KeuanganDashboard::shouldRegisterNavigation());
        $this->assertFalse(KeuanganReport::shouldRegisterNavigation());
    }

    public function test_super_admin_tanpa_permission_keuangan_tetap_dapat_mengakses_modul(): void
    {
        $admin = $this->makeUser('K0009', 'Admin', 'R01', 'admin.super@photomate.id', []);

        $this->actingAs($admin);

        $this->assertTrue(KeuanganDashboard::canAccess());
        $this->assertTrue(KeuanganDashboard::shouldRegisterNavigation());
        $this->assertTrue(KeuanganReport::canAccess());
        $this->assertTrue(KeuanganReport::shouldRegisterNavigation());

        $this->get('/admin/keuangan')->assertOk();
        $this->get('/admin/keuangan-laporan')->assertOk();

        \Livewire\Livewire::test(KeuanganReport::class)
            ->assertActionVisible('export')
            ->assertActionVisible('cetak');

        $this->get('/keuangan/laporan/cetak?periode=this_month')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
    }

    public function test_halaman_laporan_menampilkan_rincian_transaksi_individu(): void
    {
        $this->seedContohTransaksi();

        $this->actingAs($this->financeUser)
            ->get('/admin/keuangan-laporan')
            ->assertOk()
            ->assertSee('Total Pemasukan')
            ->assertSee('Total Pengeluaran')
            ->assertSee('Rp 1.518.450')
            ->assertSee('Rincian Transaksi')
            ->assertSee('Pelunasan Event A')
            ->assertSee('Pembelian tinta printer Janus')
            ->assertSee('Bensin operasional')
            ->assertSee('+ Rp 2.000.000')
            ->assertSee('- Rp 381.550')
            ->assertSee('- Rp 100.000')
            ->assertSee('Newspaper Janus')
            ->assertSee('Photomate Express');
    }

    public function test_rincian_transaksi_mengikuti_filter_cabang(): void
    {
        $this->seedContohTransaksi();

        $pelunasan = FinancialTransaction::where('description', 'Pelunasan Event A')->firstOrFail();
        $tinta = FinancialTransaction::where('description', 'Pembelian tinta printer Janus')->firstOrFail();
        $bensin = FinancialTransaction::where('description', 'Bensin operasional')->firstOrFail();

        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganReport::class)
            ->assertCanSeeTableRecords([$pelunasan, $tinta, $bensin])
            ->set('data.cabang_id', 'C0005')
            ->assertCanSeeTableRecords([$pelunasan, $bensin])
            ->assertCanNotSeeTableRecords([$tinta]);
    }

    public function test_rincian_transaksi_mengikuti_filter_periode(): void
    {
        $this->actingAs($this->financeUser);

        $transaksiBulanIni = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            1000000,
            $this->incomeCategory->id,
            null,
            Carbon::today()->toDateString(),
            'Transaksi bulan ini'
        );

        $transaksiBulanLalu = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            2000000,
            $this->incomeCategory->id,
            null,
            Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'Transaksi bulan lalu'
        );

        Livewire::test(KeuanganReport::class)
            ->assertCanSeeTableRecords([$transaksiBulanIni])
            ->assertCanNotSeeTableRecords([$transaksiBulanLalu])
            ->set('data.periode', KeuanganService::PRESET_LAST_MONTH)
            ->assertCanSeeTableRecords([$transaksiBulanLalu])
            ->assertCanNotSeeTableRecords([$transaksiBulanIni]);
    }

    public function test_rincian_transaksi_mengikuti_filter_rentang_custom(): void
    {
        $this->actingAs($this->financeUser);

        $dalamRentang = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            1000000,
            $this->incomeCategory->id,
            null,
            Carbon::today()->subDays(3)->toDateString(),
            'Transaksi dalam rentang'
        );

        $luarRentang = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            2000000,
            $this->incomeCategory->id,
            null,
            Carbon::today()->subDays(10)->toDateString(),
            'Transaksi luar rentang'
        );

        Livewire::test(KeuanganReport::class)
            ->set('data.periode', KeuanganService::PRESET_CUSTOM)
            ->set('data.dari', Carbon::today()->subDays(5)->toDateString())
            ->set('data.sampai', Carbon::today()->toDateString())
            ->assertCanSeeTableRecords([$dalamRentang])
            ->assertCanNotSeeTableRecords([$luarRentang]);
    }

    public function test_rincian_transaksi_diurutkan_dari_terbaru_dengan_waktu_sebagai_pembeda(): void
    {
        $this->actingAs($this->financeUser);

        $awalBulan = Carbon::today()->startOfMonth();

        $terlama = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            100000,
            $this->incomeCategory->id,
            null,
            $awalBulan->toDateString(),
            'Transaksi terlama'
        );

        $seriPagi = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            200000,
            $this->incomeCategory->id,
            null,
            $awalBulan->copy()->addDay()->toDateString(),
            'Transaksi seri pagi'
        );
        $seriPagi->forceFill(['created_at' => $awalBulan->copy()->addDay()->setTime(8, 0)])->saveQuietly();

        $seriSiang = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            300000,
            $this->incomeCategory->id,
            null,
            $awalBulan->copy()->addDay()->toDateString(),
            'Transaksi seri siang'
        );
        $seriSiang->forceFill(['created_at' => $awalBulan->copy()->addDay()->setTime(12, 0)])->saveQuietly();

        $terbaru = $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            400000,
            $this->incomeCategory->id,
            null,
            $awalBulan->copy()->addDays(2)->toDateString(),
            'Transaksi terbaru'
        );

        Livewire::test(KeuanganReport::class)
            ->assertCanSeeTableRecords([$terbaru, $seriSiang, $seriPagi, $terlama], inOrder: true);
    }

    public function test_rincian_transaksi_menggunakan_pagination(): void
    {
        $this->actingAs($this->financeUser);

        $awalBulan = Carbon::today()->startOfMonth();

        $records = collect(range(0, 11))->map(fn (int $index): FinancialTransaction => $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            100000 + $index,
            $this->incomeCategory->id,
            null,
            $awalBulan->copy()->addDays($index)->toDateString(),
            "Transaksi pagination {$index}"
        ));

        $terurutTerbaru = $records->sortByDesc('transaction_date')->values();

        Livewire::test(KeuanganReport::class)
            ->assertCanSeeTableRecords($terurutTerbaru->take(10))
            ->assertCanNotSeeTableRecords($terurutTerbaru->slice(10))
            ->call('gotoPage', 2, 'page')
            ->assertCanSeeTableRecords($terurutTerbaru->slice(10));
    }

    public function test_rincian_transaksi_menampilkan_empty_state(): void
    {
        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganReport::class)
            ->assertSee('Belum ada transaksi pada periode ini.');
    }

    public function test_total_di_bawah_tabel_mengikuti_filter_aktif(): void
    {
        $this->seedContohTransaksi();

        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganReport::class)
            ->assertSee('Rp 1.518.450')
            ->set('data.cabang_id', 'C0005')
            ->assertSee('Rp 1.900.000')
            ->assertDontSee('Rp 1.518.450');
    }

    public function test_export_query_mengikuti_filter_periode_dan_cabang(): void
    {
        $this->seedContohTransaksi();

        $service = app(KeuanganService::class);
        $period = $service->resolvePeriod(KeuanganService::PRESET_THIS_MONTH);

        $semuaCabang = $service->transactionsForExport($period['start'], $period['end'])->get();
        $this->assertCount(3, $semuaCabang);

        $express = $service->transactionsForExport($period['start'], $period['end'], 'C0005')->get();
        $this->assertCount(2, $express);
        $this->assertTrue($express->every(fn (FinancialTransaction $transaction): bool => $transaction->cabang_id === 'C0005'));

        $bulanLalu = $service->resolvePeriod(KeuanganService::PRESET_LAST_MONTH);
        $this->assertCount(0, $service->transactionsForExport($bulanLalu['start'], $bulanLalu['end'])->get());
    }

    public function test_cetak_pdf_laporan_membutuhkan_permission(): void
    {
        $this->seedContohTransaksi();

        $tanpaPermission = $this->makeUser('K0003', 'Account Payment', 'R05', 'account.payment@photomate.id', [
            'view_keuangan_dashboard',
            'view_keuangan_report',
            'view_any_keuangan_transaction',
        ]);

        $this->actingAs($tanpaPermission)
            ->get('/keuangan/laporan/cetak')
            ->assertForbidden();

        $this->actingAs($this->financeUser)
            ->get('/keuangan/laporan/cetak?periode=this_month')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
    }

    public function test_tamu_tidak_dapat_mengakses_cetak_pdf(): void
    {
        $this->get('/keuangan/laporan/cetak')->assertForbidden();
    }

    public function test_cetak_pdf_mengikuti_filter_cabang(): void
    {
        $this->seedContohTransaksi();

        $this->actingAs($this->financeUser)
            ->get('/keuangan/laporan/cetak?periode=this_month&cabang_id=C0005')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
    }

    public function test_template_pdf_menampilkan_rincian_transaksi_individu(): void
    {
        $this->seedContohTransaksi();

        $html = $this->renderPdfTemplate();

        $this->assertStringContainsString('Laporan Keuangan Photomate', $html);
        $this->assertStringContainsString('Periode:', $html);
        $this->assertStringContainsString('Cabang:', $html);
        $this->assertStringContainsString('Ringkasan', $html);
        $this->assertStringContainsString('Keterangan', $html);
        $this->assertStringContainsString('Rincian Transaksi', $html);

        $this->assertStringContainsString('Pelunasan Event A', $html);
        $this->assertStringContainsString('Pembelian tinta printer Janus', $html);
        $this->assertStringContainsString('Bensin operasional', $html);
        $this->assertStringContainsString('+ Rp 2.000.000', $html);
        $this->assertStringContainsString('- Rp 381.550', $html);
        $this->assertStringContainsString('- Rp 100.000', $html);
        $this->assertStringContainsString('Newspaper Janus', $html);
        $this->assertStringContainsString('Photomate Express', $html);

        $this->assertStringContainsString('Halaman {PAGE_NUM} dari {PAGE_COUNT}', $html);
        $this->assertStringContainsString('Dicetak pada:', $html);
    }

    public function test_template_pdf_tidak_lagi_menampilkan_agregasi_dan_identitas_lama(): void
    {
        $this->seedContohTransaksi();

        $html = $this->renderPdfTemplate();

        $this->assertStringNotContainsString('Rincian Pemasukan', $html);
        $this->assertStringNotContainsString('Rincian Pengeluaran', $html);
        $this->assertStringNotContainsString('Kontribusi', $html);
        $this->assertStringNotContainsString('Selisih Kas', $html);
        $this->assertStringNotContainsString('PT.QUANTA', $html);
        $this->assertStringNotContainsString('smartcool', $html);
        $this->assertStringNotContainsString('Logo not found', $html);
    }

    public function test_template_pdf_menampilkan_empty_state(): void
    {
        $html = $this->renderPdfTemplate();

        $this->assertStringContainsString('Belum ada transaksi pada periode ini.', $html);
        $this->assertStringContainsString('Rincian Transaksi', $html);
    }

    public function test_template_pdf_multi_page_mengulang_header_tabel_dan_menomori_transaksi(): void
    {
        $transactions = collect();

        for ($index = 1; $index <= 45; $index++) {
            $transactions->push($this->makeTransaction(
                FinancialTransaction::TYPE_INCOME,
                1000000 + $index,
                $this->incomeCategory->id,
                null,
                Carbon::today()->subDays($index)->toDateString(),
                "Transaksi multi page {$index}"
            ));
        }

        $html = $this->renderPdfTemplate(['transactions' => $transactions]);

        // 45 transaksi -> 12 + 16 + 16 + 1 = 4 halaman, tiap halaman punya header sendiri.
        $this->assertSame(4, substr_count($html, '<table class="transactions-table"'));
        $this->assertSame(4, substr_count($html, '<table class="table-no-border"'));
        $this->assertSame(3, substr_count($html, 'Rincian Transaksi (lanjutan)'));
        $this->assertSame(45, substr_count($html, '<td class="text-center">'));
        $this->assertStringContainsString('<td class="text-center">45</td>', $html);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->setOptions(new Options([
            'dpi' => 150,
            'defaultFont' => 'sans-serif',
            'isRemoteEnabled' => true,
            'isPhpEnabled' => true,
        ]));
        $dompdf->render();

        $this->assertSame(4, $dompdf->getCanvas()->get_page_count());
    }

    public function test_pdf_menggunakan_filter_dan_urutan_transaksi_yang_sama_dengan_halaman_laporan(): void
    {
        $this->seedContohTransaksi();

        $service = app(KeuanganService::class);
        $periode = $service->resolvePeriod(KeuanganService::PRESET_THIS_MONTH);

        $semua = $service->transactionsForReport($periode['start'], $periode['end'])->get();
        $this->assertCount(3, $semua);

        // Terbaru (created_at/id terbesar) di atas, sama seperti tabel Rincian Transaksi.
        $this->assertSame('Bensin operasional', $semua->first()->description);
        $this->assertSame('Pelunasan Event A', $semua->last()->description);

        $express = $service->transactionsForReport($periode['start'], $periode['end'], 'C0005')->get();
        $this->assertCount(2, $express);
        $this->assertTrue($express->every(fn (FinancialTransaction $transaction): bool => $transaction->cabang_id === 'C0005'));

        $bulanLalu = $service->resolvePeriod(KeuanganService::PRESET_LAST_MONTH);
        $this->assertCount(0, $service->transactionsForReport($bulanLalu['start'], $bulanLalu['end'])->get());
    }

    public function test_widget_keuangan_hanya_tampil_di_halaman_keuangan(): void
    {
        $this->assertFalse(KeuanganStatsOverview::isDiscovered());
        $this->assertFalse(IncomeExpenseChart::isDiscovered());
        $this->assertFalse(LatestTransactionsWidget::isDiscovered());

        $this->assertSame(
            [
                KeuanganStatsOverview::class,
                IncomeExpenseChart::class,
                LatestTransactionsWidget::class,
            ],
            (new KeuanganDashboard)->getWidgets()
        );
    }

    /**
     * @param  array{transactions?: \Illuminate\Support\Collection<int, FinancialTransaction>, cabangId?: ?string}  $overrides
     */
    protected function renderPdfTemplate(array $overrides = []): string
    {
        $service = app(KeuanganService::class);
        $period = $service->resolvePeriod(KeuanganService::PRESET_THIS_MONTH);
        $cabangId = $overrides['cabangId'] ?? null;

        $transactions = $overrides['transactions']
            ?? $service->transactionsForReport($period['start'], $period['end'], $cabangId)->get();

        $income = (int) $transactions->where('transaction_type', FinancialTransaction::TYPE_INCOME)->sum('amount');
        $expense = (int) $transactions->where('transaction_type', FinancialTransaction::TYPE_EXPENSE)->sum('amount');

        return view('pdf.keuangan-laporan', [
            'period' => $period,
            'cabangName' => $cabangId
                ? (Cabang::find($cabangId)?->nama_cabang ?? 'Semua Cabang')
                : 'Semua Cabang',
            'summary' => [
                'income' => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
            ],
            'transactions' => $transactions,
            'judulDokumen' => 'Laporan Keuangan Photomate',
            'periode' => $period['label'],
            'tanggalCetak' => now()->day . ' ' . MonthHelper::formatPeriod((int) now()->month, (int) now()->year),
        ])->render();
    }

    protected function seedContohTransaksi(): void
    {
        $today = Carbon::today()->toDateString();

        $this->makeTransaction(
            FinancialTransaction::TYPE_INCOME,
            2000000,
            $this->incomeCategory->id,
            'C0005',
            $today,
            'Pelunasan Event A'
        );

        $this->makeTransaction(
            FinancialTransaction::TYPE_EXPENSE,
            381550,
            $this->tintaCategory->id,
            'C0004',
            $today,
            'Pembelian tinta printer Janus'
        );

        $this->makeTransaction(
            FinancialTransaction::TYPE_EXPENSE,
            100000,
            $this->bensinCategory->id,
            'C0005',
            $today,
            'Bensin operasional'
        );
    }

    protected function makeTransaction(
        string $type,
        int $amount,
        int $categoryId,
        ?string $cabangId,
        string $date,
        string $description = 'Transaksi uji'
    ): FinancialTransaction {
        return FinancialTransaction::create([
            'transaction_type' => $type,
            'amount' => $amount,
            'category_id' => $categoryId,
            'cabang_id' => $cabangId,
            'payment_method_id' => $this->paymentMethod->id,
            'transaction_date' => $date,
            'description' => $description,
        ]);
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
            'alamat' => 'Jakarta',
        ]);

        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
