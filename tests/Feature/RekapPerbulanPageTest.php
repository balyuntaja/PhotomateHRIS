<?php

namespace Tests\Feature;

use App\Filament\Pages\RekapPerbulan;
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
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\RekapPerbulanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RekapPerbulanPageTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $superAdmin;
    protected Karyawan $financeUser;
    protected Karyawan $regularCrew;

    protected Cabang $janusCabang;
    protected Cabang $golioCabang;
    protected Cabang $expressCabang;

    protected FinancialParentCategory $boothIncomeParent;
    protected FinancialParentCategory $boxParent;
    protected FinancialParentCategory $gajiParent;
    protected FinancialParentCategory $persediaanParent;

    protected FinancialCategory $boothIncomeCategory;
    protected FinancialCategory $boxCategory;
    protected FinancialCategory $gajiCategory;

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

        $this->janusCabang = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->golioCabang = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Golio',
        ]);

        $this->expressCabang = Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        // Setup Parent Categories
        $this->boothIncomeParent = FinancialParentCategory::firstOrCreate(
            ['name' => 'Pendapatan Booth'],
            ['is_active' => true]
        );

        $this->boxParent = FinancialParentCategory::firstOrCreate(
            ['name' => 'Box'],
            ['is_active' => true]
        );

        $this->gajiParent = FinancialParentCategory::firstOrCreate(
            ['name' => 'Gaji'],
            ['is_active' => true]
        );

        $this->persediaanParent = FinancialParentCategory::firstOrCreate(
            ['name' => 'Persediaan'],
            ['is_active' => true]
        );

        // Setup Categories
        $this->boothIncomeCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Sesi Booth', 'transaction_type' => FinancialCategory::TYPE_INCOME],
            ['parent_category_id' => $this->boothIncomeParent->id, 'is_active' => true]
        );
        $this->boothIncomeCategory->update(['parent_category_id' => $this->boothIncomeParent->id]);

        $this->boxCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Box Packaging', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $this->boxParent->id, 'is_active' => true]
        );
        $this->boxCategory->update(['parent_category_id' => $this->boxParent->id]);

        $this->gajiCategory = FinancialCategory::firstOrCreate(
            ['name' => 'Gaji Pokok Crew', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['parent_category_id' => $this->gajiParent->id, 'is_active' => true]
        );
        $this->gajiCategory->update(['parent_category_id' => $this->gajiParent->id]);

        // Setup Users
        $this->superAdmin = $this->makeUser('K0001', 'Super Admin', 'R01', 'superadmin@photomate.id', ['super_admin']);
        $this->financeUser = $this->makeUser('K0002', 'Finance Manager', 'R04', 'finance@photomate.id', [
            'view_keuangan_report',
            'print_keuangan_report',
        ]);
        $this->regularCrew = $this->makeUser('K0003', 'Crew', 'R05', 'crew@photomate.id', []);
    }

    public function test_guest_diarahkan_ke_login(): void
    {
        $this->get('/admin/rekap-perbulan')->assertRedirect('/admin/login');
    }

    public function test_user_tanpa_permission_ditolak_403(): void
    {
        $this->actingAs($this->regularCrew)
            ->get('/admin/rekap-perbulan')
            ->assertForbidden();
    }

    public function test_finance_user_dapat_mengakses_halaman(): void
    {
        $this->actingAs($this->financeUser)
            ->get('/admin/rekap-perbulan')
            ->assertOk()
            ->assertSee('REKAP PERBULAN');
    }

    public function test_super_admin_dapat_mengakses_halaman(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/rekap-perbulan')
            ->assertOk()
            ->assertSee('REKAP PERBULAN');
    }

    public function test_halaman_menampilkan_komponen_lengkap_dan_perhitungan_konsisten(): void
    {
        $this->actingAs($this->financeUser);

        // Buat data transaksi bulan September 2026
        $date = '2026-09-15';

        // 1. Income
        // Janus: 13.965.000
        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 13965000,
            'parent_category_id' => $this->boothIncomeParent->id,
            'category_id' => $this->boothIncomeCategory->id,
            'cabang_id' => $this->janusCabang->cabang_id,
            'transaction_date' => $date,
            'description' => 'Income Janus Sept',
        ]);

        // Golio: 8.805.000
        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 8805000,
            'parent_category_id' => $this->boothIncomeParent->id,
            'category_id' => $this->boothIncomeCategory->id,
            'cabang_id' => $this->golioCabang->cabang_id,
            'transaction_date' => $date,
            'description' => 'Income Golio Sept',
        ]);

        // Express: 14.993.000
        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 14993000,
            'parent_category_id' => $this->boothIncomeParent->id,
            'category_id' => $this->boothIncomeCategory->id,
            'cabang_id' => $this->expressCabang->cabang_id,
            'transaction_date' => $date,
            'description' => 'Income Express Sept',
        ]);

        // 2. Expenses / HPP
        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 4320000,
            'parent_category_id' => $this->boxParent->id,
            'category_id' => $this->boxCategory->id,
            'cabang_id' => $this->janusCabang->cabang_id,
            'transaction_date' => $date,
            'description' => 'Box Janus',
        ]);

        // 3. Utang
        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 31616000,
            'parent_category_id' => $this->boxParent->id,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
            'due_date' => '2028-11-15', // ~26 bulan dari Sept 2026
            'counterparty' => 'Bank KUR',
            'description' => 'KUR Modal',
            'transaction_date' => $date,
        ]);

        // 4. Rekap Sesi Approved untuk Sesi & Lembar
        $reportJanus = SessionReport::create([
            'report_number' => 'SR-20260915-001',
            'cabang_id' => $this->janusCabang->cabang_id,
            'report_date' => $date,
            'status' => SessionReport::STATUS_APPROVED,
            'total_transactions' => 377,
            'total_sessions' => 540,
            'grand_total_amount' => 13965000,
        ]);

        // Render Halaman Livewire dengan filter September 2026
        Livewire::test(RekapPerbulan::class)
            ->set('data.bulan', 9)
            ->set('data.tahun', 2026)
            ->assertSee('REKAP PERBULAN')
            ->assertSee('SEPTEMBER 2026')
            ->assertSee('SUMMARY SEPTEMBER')
            ->assertSee('INCOME SEPTEMBER')
            ->assertSee('EXPENSES SEPTEMBER')
            ->assertSee('UTANG')
            ->assertSee('Janus')
            ->assertSee('Golio')
            ->assertSee('Express')
            ->assertSee('Rp 13.965.000')
            ->assertSee('Rp 8.805.000')
            ->assertSee('Rp 14.993.000')
            ->assertSee('Rp 37.763.000')
            ->assertSee('540') // Lembar
            ->assertSee('377') // Sesi
            ->assertSee('KUR Modal')
            ->assertSee('Export PDF');
    }

    public function test_cetak_pdf_rekap_perbulan_menghasilkan_dokumen_pdf_landscape(): void
    {
        $this->actingAs($this->financeUser);

        $response = $this->get('/keuangan/rekap-perbulan/cetak?bulan=9&tahun=2026')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('rekap-perbulan-photomate-2026-09.pdf', $disposition);
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
