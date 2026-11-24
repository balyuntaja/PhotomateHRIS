<?php

namespace Tests\Feature;

use App\Filament\Pages\KeuanganLabaRugiReport;
use App\Filament\Pages\KeuanganReport;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Permission;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Services\KeuanganService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KeuanganLabaRugiReportTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $financeUser;

    protected Karyawan $regularUser;

    protected FinancialAccount $expressAccount;

    protected FinancialAccount $janusAccount;

    protected FinancialAccount $golioAccount;

    protected FinancialCategory $incomeCategory;

    protected FinancialParentCategory $boxParent;

    protected FinancialParentCategory $gajiParent;

    protected FinancialParentCategory $transportParent;

    protected FinancialCategory $boxCategory;

    protected FinancialCategory $gajiCategory;

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

        $this->expressAccount = FinancialAccount::where('name', 'Photomate Express')->firstOrFail();
        $this->janusAccount = FinancialAccount::where('name', 'Janus')->firstOrFail();
        $this->golioAccount = FinancialAccount::where('name', 'Golio')->firstOrFail();

        $this->incomeCategory = FinancialCategory::where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->boxParent = FinancialParentCategory::where('name', 'Box')->firstOrFail();
        $this->gajiParent = FinancialParentCategory::where('name', 'Gaji')->firstOrFail();
        $this->transportParent = FinancialParentCategory::where('name', 'Transportasi')->firstOrFail();

        $this->boxCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Box Kardus', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $this->boxParent->id, 'is_active' => true]
        );
        $this->boxCategory->update(['parent_category_id' => $this->boxParent->id]);

        $this->gajiCategory = FinancialCategory::where('name', 'Gaji')->first()
            ?? FinancialCategory::create([
                'name' => 'Gaji Pokok',
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'parent_category_id' => $this->gajiParent->id,
                'is_active' => true,
            ]);
        $this->gajiCategory->update(['parent_category_id' => $this->gajiParent->id]);

        $this->paymentMethod = FinancialPaymentMethod::where('name', 'Transfer')->firstOrFail();

        $this->financeUser = $this->makeUser('K0001', 'Manager Finance', 'R04', 'finance@photomate.id', [
            'view_keuangan_dashboard',
            'view_keuangan_report',
            'export_keuangan_report',
            'print_keuangan_report',
            'view_any_keuangan_transaction',
        ]);

        $this->regularUser = $this->makeUser('K0002', 'Staff Kasir', 'R05', 'staff@photomate.id', [
            'view_keuangan_dashboard',
        ]);
    }

    public function test_halaman_laba_rugi_menolak_user_tanpa_permission(): void
    {
        $this->actingAs($this->regularUser);
        $this->get('/admin/keuangan-laba-rugi')->assertForbidden();
        $this->assertFalse(KeuanganLabaRugiReport::canAccess());
    }

    public function test_halaman_laba_rugi_dapat_diakses_user_dengan_permission_dan_terdaftar_di_sidebar(): void
    {
        $this->actingAs($this->financeUser);
        $this->get('/admin/keuangan-laba-rugi')->assertOk();

        // Navigasi
        $this->assertTrue(KeuanganLabaRugiReport::shouldRegisterNavigation());
        $this->assertSame('Keuangan', KeuanganLabaRugiReport::getNavigationGroup());
        $this->assertSame('Laporan Laba Rugi', KeuanganLabaRugiReport::getNavigationLabel());
        $this->assertSame(5, KeuanganLabaRugiReport::getNavigationSort());

        // Jurnal Umum berada tepat di atasnya (sort = 4)
        $this->assertSame(4, KeuanganReport::getNavigationSort());
    }

    public function test_halaman_laba_rugi_menampilkan_pendapatan_beban_utama_dan_status_laba(): void
    {
        $today = Carbon::today()->toDateString();

        // Pemasukan: Express 5jt, Janus 3jt, Golio 2jt = Total 10jt
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 5000000, $this->incomeCategory->id, 'C0005', $today, 'Express Income', $this->expressAccount->id);
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 3000000, $this->incomeCategory->id, 'C0004', $today, 'Janus Income', $this->janusAccount->id);
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 2000000, $this->incomeCategory->id, null, $today, 'Golio Income', $this->golioAccount->id);

        // Pengeluaran: Box 1jt, Gaji 2.5jt = Total Beban 3.5jt
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 1000000, $this->boxCategory->id, 'C0005', $today, 'Beli Box', $this->expressAccount->id, $this->boxParent->id);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 2500000, $this->gajiCategory->id, 'C0004', $today, 'Gaji Crew', $this->janusAccount->id, $this->gajiParent->id);

        // Laba Bersih = 10jt - 3.5jt = 6.5jt (Status: Laba)
        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertSee('Laporan Laba Rugi')
            ->assertSee('A. Pendapatan Usaha')
            ->assertSee('Express')
            ->assertSee('Janus')
            ->assertSee('Golio')
            ->assertSee('Rp 5.000.000')
            ->assertSee('Rp 3.000.000')
            ->assertSee('Rp 2.000.000')
            ->assertSee('Total Pendapatan')
            ->assertSee('Rp 10.000.000')
            ->assertSee('B. Beban Usaha')
            ->assertSee('Box')
            ->assertSee('Gaji')
            ->assertSee('Rp 1.000.000')
            ->assertSee('Rp 2.500.000')
            ->assertSee('Total Beban Usaha')
            ->assertSee('Rp 3.500.000')
            ->assertSee('C. Laba / Rugi')
            ->assertSee('Laba Bersih')
            ->assertSee('Rp 6.500.000')
            // Tidak menampilkan rincian transaksi individu satu per satu
            ->assertDontSee('Express Income')
            ->assertDontSee('Janus Income')
            ->assertDontSee('Golio Income')
            ->assertDontSee('Beli Box')
            ->assertDontSee('Gaji Crew');
    }

    public function test_halaman_laba_rugi_menampilkan_status_rugi_dan_impas(): void
    {
        $today = Carbon::today()->toDateString();

        // Income 2jt, Expense Box 3jt -> Rugi 1jt
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 2000000, $this->incomeCategory->id, 'C0005', $today, 'Income Express', $this->expressAccount->id);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 3000000, $this->boxCategory->id, 'C0005', $today, 'Expense Box', $this->expressAccount->id, $this->boxParent->id);

        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertSee('Rugi Bersih')
            ->assertSee('-Rp 1.000.000');

        // Tambah Income 1jt -> Total Income 3jt, Expense 3jt -> Impas
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 1000000, $this->incomeCategory->id, 'C0005', $today, 'Income Extra', $this->expressAccount->id);

        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertSee('Impas Bersih')
            ->assertSee('Rp 0');
    }

    public function test_halaman_laba_rugi_mengikuti_filter_akun(): void
    {
        $today = Carbon::today()->toDateString();

        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 5000000, $this->incomeCategory->id, 'C0005', $today, 'Income Express', $this->expressAccount->id);
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 3000000, $this->incomeCategory->id, 'C0004', $today, 'Income Janus', $this->janusAccount->id);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 500000, $this->boxCategory->id, 'C0004', $today, 'Expense Janus', $this->janusAccount->id, $this->boxParent->id);

        $this->actingAs($this->financeUser);

        // Filter akun Janus
        Livewire::test(KeuanganLabaRugiReport::class)
            ->set('data.account_id', $this->janusAccount->id)
            ->assertSee('Janus')
            ->assertSee('Rp 3.000.000')
            ->assertSee('Rp 500.000')
            ->assertSee('Rp 2.500.000')
            // Express income harus bernilai Rp 0 saat filter akun Janus
            ->assertSee('Rp 0');
    }

    public function test_halaman_laba_rugi_mengikuti_filter_periode_dan_tahun_ini(): void
    {
        $this->actingAs($this->financeUser);

        // Test filter preset tahun ini
        Livewire::test(KeuanganLabaRugiReport::class)
            ->set('data.periode', KeuanganService::PRESET_THIS_YEAR)
            ->assertSee('Tahun ' . now()->year);

        // Test filter rentang custom
        $kemarin = Carbon::yesterday()->toDateString();
        $hariIni = Carbon::today()->toDateString();

        Livewire::test(KeuanganLabaRugiReport::class)
            ->set('data.periode', KeuanganService::PRESET_CUSTOM)
            ->set('data.dari', $kemarin)
            ->set('data.sampai', $hariIni)
            ->assertOk();
    }

    public function test_halaman_laba_rugi_menampilkan_rp_0_saat_tidak_ada_transaksi(): void
    {
        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertSee('Total Pendapatan')
            ->assertSee('Total Beban Usaha')
            ->assertSee('Rp 0');
    }

    public function test_action_export_csv_berfungsi(): void
    {
        $today = Carbon::today()->toDateString();

        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 5000000, $this->incomeCategory->id, 'C0005', $today, 'Express', $this->expressAccount->id);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 1000000, $this->boxCategory->id, 'C0005', $today, 'Box', $this->expressAccount->id, $this->boxParent->id);

        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertActionVisible('export')
            ->callAction('export')
            ->assertFileDownloaded();
    }

    public function test_action_cetak_pdf_tersedia_dan_mengarah_ke_laporan_laba_rugi(): void
    {
        $this->actingAs($this->financeUser);

        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertActionVisible('cetak');

        $response = $this->get('/keuangan/laporan/cetak?format=laba_rugi&periode=this_month')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('laporan-laba-rugi-', $disposition);
    }

    public function test_skenario_a_periode_banyak_transaksi_seluruh_kategori_utama_muncul_dan_kategori_tanpa_transaksi_rp_0(): void
    {
        $today = Carbon::today()->toDateString();
        $service = app(KeuanganService::class);
        $masterCategories = $service->getExpenseParentCategoryNames();

        // Siapkan beberapa kategori utama dan subkategorinya
        $persediaanParent = FinancialParentCategory::where('name', 'Persediaan')->firstOrFail();
        $langgananParent = FinancialParentCategory::where('name', 'Langganan')->firstOrFail();
        $tenantParent = FinancialParentCategory::where('name', 'Tenant/Sharprof')->firstOrFail();

        $persediaanCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Kertas Foto Uji', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $persediaanParent->id, 'is_active' => true]
        );
        $persediaanCategory->update(['parent_category_id' => $persediaanParent->id]);

        $langgananCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Server Cloud Uji', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $langgananParent->id, 'is_active' => true]
        );
        $langgananCategory->update(['parent_category_id' => $langgananParent->id]);

        $tenantCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Bagi Hasil Uji', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $tenantParent->id, 'is_active' => true]
        );
        $tenantCategory->update(['parent_category_id' => $tenantParent->id]);

        $lainLainCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Biaya Lain-lain Uji', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => null, 'is_active' => true]
        );
        $lainLainCategory->update(['parent_category_id' => null]);

        // Buat transaksi pada beberapa kategori
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 5205417, $persediaanCategory->id, 'C0005', $today, 'Persediaan', $this->expressAccount->id, $persediaanParent->id);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 2580990, $langgananCategory->id, 'C0005', $today, 'Langganan', $this->expressAccount->id, $langgananParent->id);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 1724800, $lainLainCategory->id, 'C0005', $today, 'Lain-lain', $this->expressAccount->id, null); // fallback Lain-lain
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 989325, $tenantCategory->id, 'C0005', $today, 'Tenant', $this->expressAccount->id, $tenantParent->id);

        $report = $service->labaRugiReport(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth());

        // 1. Seluruh kategori utama Beban Usaha muncul
        foreach ($masterCategories as $catName) {
            $this->assertArrayHasKey($catName, $report['beban']['rows'], "Kategori $catName wajib ada di laporan beban");
        }

        // 2. Kategori yang bertransaksi menampilkan nilai yang tepat
        $this->assertSame(5205417, $report['beban']['rows']['Persediaan']);
        $this->assertSame(2580990, $report['beban']['rows']['Langganan']);
        $this->assertSame(1724800, $report['beban']['rows']['Lain-lain']);
        $this->assertSame(989325, $report['beban']['rows']['Tenant/Sharprof']);

        // 3. Kategori yang TIDAK bertransaksi bernilai 0
        $this->assertSame(0, $report['beban']['rows']['Box']);
        $this->assertSame(0, $report['beban']['rows']['Transportasi']);
        $this->assertSame(0, $report['beban']['rows']['Gaji']);
        $this->assertSame(0, $report['beban']['rows']['Konsumsi']);
        $this->assertSame(0, $report['beban']['rows']['RnD']);

        // 4. Total Beban Usaha akurat
        $this->assertSame(5205417 + 2580990 + 1724800 + 989325, $report['beban']['total']);

        // 5. Verifikasi tampilan Livewire
        $this->actingAs($this->financeUser);
        $testLivewire = Livewire::test(KeuanganLabaRugiReport::class);
        foreach ($masterCategories as $catName) {
            $testLivewire->assertSee($catName);
        }
        $testLivewire->assertSee('Rp 5.205.417');
        $testLivewire->assertSee('Rp 2.580.990');
        $testLivewire->assertSee('Rp 1.724.800');
        $testLivewire->assertSee('Rp 0');
    }

    public function test_skenario_b_periode_sedikit_transaksi_kategori_lain_tetap_muncul_rp_0(): void
    {
        $today = Carbon::today()->toDateString();
        $service = app(KeuanganService::class);
        $masterCategories = $service->getExpenseParentCategoryNames();

        // Hanya 1 transaksi pengeluaran (Gaji 3.000.000)
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 3000000, $this->gajiCategory->id, 'C0005', $today, 'Gaji Staff', $this->expressAccount->id, $this->gajiParent->id);

        $report = $service->labaRugiReport(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth());

        // Kategori yang memiliki transaksi
        $this->assertSame(3000000, $report['beban']['rows']['Gaji']);
        $this->assertSame(3000000, $report['beban']['total']);

        // Seluruh kategori lainnya tetap muncul dengan nominal 0
        foreach ($masterCategories as $catName) {
            $this->assertArrayHasKey($catName, $report['beban']['rows']);
            if ($catName !== 'Gaji') {
                $this->assertSame(0, $report['beban']['rows'][$catName], "Kategori $catName harus bernilai 0");
            }
        }

        $this->actingAs($this->financeUser);
        Livewire::test(KeuanganLabaRugiReport::class)
            ->assertSee('Gaji')
            ->assertSee('Rp 3.000.000')
            ->assertSee('Total Beban Usaha')
            ->assertSee('Rp 0');
    }

    public function test_skenario_c_periode_tanpa_transaksi_pengeluaran_seluruh_kategori_utama_rp_0(): void
    {
        $today = Carbon::today()->toDateString();
        $service = app(KeuanganService::class);
        $masterCategories = $service->getExpenseParentCategoryNames();

        // Hanya ada pendapatan 7.354.000, tidak ada transaksi pengeluaran sama sekali
        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 7354000, $this->incomeCategory->id, 'C0005', $today, 'Income Express', $this->expressAccount->id);

        $report = $service->labaRugiReport(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth());

        // Seluruh kategori utama Beban Usaha tetap muncul dengan 0
        foreach ($masterCategories as $catName) {
            $this->assertArrayHasKey($catName, $report['beban']['rows']);
            $this->assertSame(0, $report['beban']['rows'][$catName]);
        }

        // Total beban 0
        $this->assertSame(0, $report['beban']['total']);

        // Laba bersih sama dengan total pendapatan
        $this->assertSame(7354000, $report['ringkasan']['total_pendapatan']);
        $this->assertSame(7354000, $report['ringkasan']['laba_rugi']);
        $this->assertSame('laba', $report['ringkasan']['status']);

        $this->actingAs($this->financeUser);
        $test = Livewire::test(KeuanganLabaRugiReport::class);
        foreach ($masterCategories as $catName) {
            $test->assertSee($catName);
        }
        $test->assertSee('Rp 7.354.000')
            ->assertSee('Total Beban Usaha')
            ->assertSee('Rp 0');
    }

    public function test_skenario_d_filter_berdasarkan_akun_tetap_menampilkan_seluruh_kategori_utama(): void
    {
        $today = Carbon::today()->toDateString();
        $service = app(KeuanganService::class);
        $masterCategories = $service->getExpenseParentCategoryNames();

        // Transaksi di Express Account
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 1500000, $this->boxCategory->id, 'C0005', $today, 'Box Express', $this->expressAccount->id, $this->boxParent->id);

        // Transaksi di Janus Account
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 2000000, $this->gajiCategory->id, 'C0004', $today, 'Gaji Janus', $this->janusAccount->id, $this->gajiParent->id);

        // Filter akun Janus
        $reportJanus = $service->labaRugiReport(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth(), $this->janusAccount->id);

        // Semua kategori utama tetap muncul
        foreach ($masterCategories as $catName) {
            $this->assertArrayHasKey($catName, $reportJanus['beban']['rows']);
        }

        // Box harus 0 pada akun Janus (karena transaksi Box ada di Express)
        $this->assertSame(0, $reportJanus['beban']['rows']['Box']);
        // Gaji harus 2.000.000 pada akun Janus
        $this->assertSame(2000000, $reportJanus['beban']['rows']['Gaji']);
        $this->assertSame(2000000, $reportJanus['beban']['total']);

        // Filter akun Express
        $reportExpress = $service->labaRugiReport(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth(), $this->expressAccount->id);
        $this->assertSame(1500000, $reportExpress['beban']['rows']['Box']);
        $this->assertSame(0, $reportExpress['beban']['rows']['Gaji']);
        $this->assertSame(1500000, $reportExpress['beban']['total']);
    }

    public function test_skenario_e_transaksi_subkategori_diagregasikan_ke_kategori_utama_tanpa_duplikasi(): void
    {
        $today = Carbon::today()->toDateString();
        $service = app(KeuanganService::class);

        $persediaanParent = FinancialParentCategory::where('name', 'Persediaan')->firstOrFail();

        // 2 Subkategori di bawah Persediaan
        $kertasSub = FinancialCategory::firstOrCreate(
            ['name' => 'Kertas Foto', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $persediaanParent->id, 'is_active' => true]
        );
        $kertasSub->update(['parent_category_id' => $persediaanParent->id]);

        $tintaSub = FinancialCategory::firstOrCreate(
            ['name' => 'Tinta Printer', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $persediaanParent->id, 'is_active' => true]
        );
        $tintaSub->update(['parent_category_id' => $persediaanParent->id]);

        // Transaksi 1: Pakai subkategori Kertas Foto (300.000)
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 300000, $kertasSub->id, 'C0005', $today, 'Beli Kertas', $this->expressAccount->id, null);

        // Transaksi 2: Pakai subkategori Tinta Printer (200.000)
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 200000, $tintaSub->id, 'C0005', $today, 'Beli Tinta', $this->expressAccount->id, $persediaanParent->id);

        // Transaksi 3: Langsung ke kategori utama Persediaan (500.000)
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 500000, $kertasSub->id, 'C0005', $today, 'Persediaan Umum', $this->expressAccount->id, $persediaanParent->id);

        $report = $service->labaRugiReport(Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth());

        // 1. Subkategori tidak muncul sebagai baris terpisah
        $this->assertArrayNotHasKey('Kertas Foto', $report['beban']['rows']);
        $this->assertArrayNotHasKey('Tinta Printer', $report['beban']['rows']);

        // 2. Kategori utama Persediaan menjumlahkan seluruh subkategori + langsung (300rb + 200rb + 500rb = 1jt)
        $this->assertSame(1000000, $report['beban']['rows']['Persediaan']);

        // 3. Total Beban tidak mengalami double counting
        $this->assertSame(1000000, $report['beban']['total']);
    }

    public function test_skenario_f_export_csv_dan_cetak_pdf_memuat_seluruh_kategori_utama(): void
    {
        $today = Carbon::today()->toDateString();
        $service = app(KeuanganService::class);
        $masterCategories = $service->getExpenseParentCategoryNames();

        // Hanya 1 transaksi pengeluaran (Transportasi 250.000)
        $transportCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Bensin Kendaraan Uji', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $this->transportParent->id, 'is_active' => true]
        );
        $transportCategory->update(['parent_category_id' => $this->transportParent->id]);

        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 250000, $transportCategory->id, 'C0005', $today, 'Bensin', $this->expressAccount->id, $this->transportParent->id);

        $this->actingAs($this->financeUser);

        // 1. Uji komponen reportData pada halaman Filament
        $page = Livewire::test(KeuanganLabaRugiReport::class);
        $reportData = $page->instance()->reportData();

        foreach ($masterCategories as $catName) {
            $this->assertArrayHasKey($catName, $reportData['beban']['rows']);
        }
        $this->assertSame(250000, $reportData['beban']['rows']['Transportasi']);
        $this->assertSame(0, $reportData['beban']['rows']['Box']);
        $this->assertSame(0, $reportData['beban']['rows']['Gaji']);

        // 2. Uji Action Export CSV
        $page->assertActionVisible('export')
            ->callAction('export')
            ->assertFileDownloaded();

        // 3. Uji Cetak PDF
        $response = $this->get('/keuangan/laporan/cetak?format=laba_rugi&periode=this_month')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('laporan-laba-rugi-', $disposition);
    }

    protected function makeTransaction(
        string $type,
        int $amount,
        int $categoryId,
        ?string $cabangId,
        string $date,
        string $description = 'Transaksi uji',
        ?int $accountId = null,
        ?int $parentCategoryId = null
    ): FinancialTransaction {
        return FinancialTransaction::create([
            'transaction_type' => $type,
            'amount' => $amount,
            'category_id' => $categoryId,
            'parent_category_id' => $parentCategoryId,
            'account_id' => $accountId,
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
