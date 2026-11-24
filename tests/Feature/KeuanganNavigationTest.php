<?php

namespace Tests\Feature;

use App\Filament\Pages\KeuanganDashboard;
use App\Filament\Pages\KeuanganInputTransaksi;
use App\Filament\Pages\KeuanganLabaRugiReport;
use App\Filament\Pages\KeuanganReport;
use App\Filament\Pages\RekapPerbulan;
use App\Filament\Resources\FinancialCategoryResource;
use App\Filament\Resources\FinancialParentCategoryResource;
use App\Filament\Resources\FinancialPaymentMethodResource;
use App\Filament\Resources\FinancialTransactionResource;
use App\Models\Karyawan;
use App\Models\Permission;
use App\Models\Perusahaan;
use App\Models\Role;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KeuanganNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $superAdmin;
    protected Karyawan $userWithDashboardPermission;
    protected Karyawan $userWithReportPermission;
    protected Karyawan $userWithoutPermission;

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

        $this->superAdmin = $this->makeUser('K0001', 'Super Admin', 'R01', 'superadmin@photomate.id', ['super_admin']);
        $this->userWithDashboardPermission = $this->makeUser('K0002', 'Dashboard User', 'R04', 'dashboard.user@photomate.id', [
            'view_keuangan_dashboard',
        ]);
        $this->userWithReportPermission = $this->makeUser('K0003', 'Report User', 'R05', 'report.user@photomate.id', [
            'view_keuangan_report',
        ]);
        $this->userWithoutPermission = $this->makeUser('K0004', 'Staff Regular', 'R06', 'crew@photomate.id', [
            'view_any_karyawan',
        ]);
    }

    public function test_susunan_submenu_keuangan_sesuai_urutan_spesifikasi(): void
    {
        // 1. Dashboard (sebelumnya Rekap Perbulan)
        $this->assertSame('Keuangan', RekapPerbulan::getNavigationGroup());
        $this->assertSame('Dashboard', RekapPerbulan::getNavigationLabel());
        $this->assertSame(1, RekapPerbulan::getNavigationSort());

        // 2. Transaksi
        $this->assertSame('Keuangan', FinancialTransactionResource::getNavigationGroup());
        $this->assertSame('Transaksi', FinancialTransactionResource::getNavigationLabel());
        $this->assertSame(2, FinancialTransactionResource::getNavigationSort());

        // 3. Input Transaksi
        $this->assertSame('Keuangan', KeuanganInputTransaksi::getNavigationGroup());
        $this->assertSame('Input Transaksi', KeuanganInputTransaksi::getNavigationLabel());
        $this->assertSame(3, KeuanganInputTransaksi::getNavigationSort());

        // 4. Jurnal Umum
        $this->assertSame('Keuangan', KeuanganReport::getNavigationGroup());
        $this->assertSame('Jurnal Umum', KeuanganReport::getNavigationLabel());
        $this->assertSame(4, KeuanganReport::getNavigationSort());

        // 5. Laporan Laba Rugi
        $this->assertSame('Keuangan', KeuanganLabaRugiReport::getNavigationGroup());
        $this->assertSame('Laporan Laba Rugi', KeuanganLabaRugiReport::getNavigationLabel());
        $this->assertSame(5, KeuanganLabaRugiReport::getNavigationSort());

        // 6. Kategori Keuangan
        $this->assertSame('Keuangan', FinancialCategoryResource::getNavigationGroup());
        $this->assertSame('Kategori Keuangan', FinancialCategoryResource::getNavigationLabel());
        $this->assertSame(6, FinancialCategoryResource::getNavigationSort());

        // 7. Metode Pembayaran
        $this->assertSame('Keuangan', FinancialPaymentMethodResource::getNavigationGroup());
        $this->assertSame('Metode Pembayaran', FinancialPaymentMethodResource::getNavigationLabel());
        $this->assertSame(7, FinancialPaymentMethodResource::getNavigationSort());
    }

    public function test_dashboard_keuangan_tidak_terdaftar_di_navigasi_sidebar(): void
    {
        $this->assertFalse(KeuanganDashboard::shouldRegisterNavigation());
        $this->assertFalse(FinancialParentCategoryResource::shouldRegisterNavigation());
    }

    public function test_akses_url_keuangan_oleh_user_finance_mengalihkan_ke_rekap_perbulan(): void
    {
        $this->actingAs($this->userWithReportPermission)
            ->get('/admin/keuangan')
            ->assertRedirect('/admin/rekap-perbulan');
    }

    public function test_akses_url_keuangan_oleh_super_admin_mengalihkan_ke_rekap_perbulan(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/keuangan')
            ->assertRedirect('/admin/rekap-perbulan');
    }

    public function test_user_dengan_permission_view_keuangan_dashboard_dapat_mengakses_rekap_perbulan(): void
    {
        $this->actingAs($this->userWithDashboardPermission);

        $this->assertTrue(RekapPerbulan::canAccess());
        $this->get('/admin/rekap-perbulan')
            ->assertOk()
            ->assertSee('REKAP PERBULAN');
    }

    public function test_user_dengan_permission_view_keuangan_report_dapat_mengakses_rekap_perbulan(): void
    {
        $this->actingAs($this->userWithReportPermission);

        $this->assertTrue(RekapPerbulan::canAccess());
        $this->get('/admin/rekap-perbulan')
            ->assertOk()
            ->assertSee('REKAP PERBULAN');
    }

    public function test_user_tanpa_permission_ditolak_mengakses_keuangan_dan_rekap_perbulan(): void
    {
        $this->actingAs($this->userWithoutPermission);

        $this->assertFalse(RekapPerbulan::canAccess());
        $this->get('/admin/rekap-perbulan')->assertForbidden();
        $this->get('/admin/keuangan')->assertForbidden();
    }

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
