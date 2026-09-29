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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_halaman_laporan_menampilkan_breakdown_kategori_dan_per_cabang(): void
    {
        $this->seedContohTransaksi();

        $this->actingAs($this->financeUser)
            ->get('/admin/keuangan-laporan')
            ->assertOk()
            ->assertSee('Total Pemasukan')
            ->assertSee('Total Pengeluaran')
            ->assertSee('Rp 1.518.450')
            ->assertSee('Pelunasan')
            ->assertSee('Tinta')
            ->assertSee('Selisih Kas')
            ->assertSee('Newspaper Janus')
            ->assertSee('Photomate Express');
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
