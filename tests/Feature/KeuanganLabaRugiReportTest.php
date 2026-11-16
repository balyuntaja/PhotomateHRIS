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
        $this->assertSame(6, KeuanganLabaRugiReport::getNavigationSort());

        // Laporan Detail Transaksi berada tepat di atasnya (sort = 5)
        $this->assertSame(5, KeuanganReport::getNavigationSort());
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
