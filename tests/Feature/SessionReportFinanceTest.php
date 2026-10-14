<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\KeuanganService;
use App\Services\SessionReportFinancialService;
use App\Services\SessionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SessionReportFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $supervisor;

    protected Karyawan $crew;

    protected Cabang $express;

    protected Cabang $unmappedBranch;

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

        $this->express = Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
            'alamat' => 'Photomate Express Outlet',
        ]);

        $this->unmappedBranch = Cabang::create([
            'cabang_id' => 'C0100',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Booth Baru',
            'alamat' => 'Booth Baru Outlet',
        ]);

        $this->crew = $this->makeUser('K0002', 'Crew Booth', 'R07', 'crew@photomate.id', []);
        $this->supervisor = $this->makeUser('K0001', 'Supervisor', 'R08', 'supervisor@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
        ]);
    }

    public function test_approval_mencatat_transaksi_keuangan_per_metode_dengan_kategori_pendapatan_booth(): void
    {
        $this->actingAs($this->supervisor);

        $report = $this->makeReport($this->express, [
            ['payment_method' => 'CASH', 'amount' => 30000],
            ['payment_method' => 'QRIS', 'amount' => 45000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->supervisor);

        $category = $this->pendapatanBoothCategory();

        $this->assertSame(SessionReport::STATUS_APPROVED, $report->fresh()->status);
        $this->assertSame(2, FinancialTransaction::count());

        $cash = FinancialTransaction::where('source_id', $report->id . '-CASH')->firstOrFail();
        $this->assertSame(FinancialTransaction::TYPE_INCOME, $cash->transaction_type);
        $this->assertSame($category->id, $cash->category_id);
        $this->assertSame('Photomate Express', $cash->account?->name);
        $this->assertSame('Photomate Express', $cash->cabang?->nama_cabang);
        $this->assertSame(30000, $cash->amount);
        $this->assertSame('Cash', $cash->paymentMethod?->name);
        $this->assertSame('2026-10-11', $cash->transaction_date->toDateString());
        $this->assertSame(FinancialTransaction::PAYMENT_STATUS_LUNAS, $cash->payment_status);
        $this->assertSame(SessionReportFinancialService::SOURCE, $cash->source_type);
        $this->assertSame($this->supervisor->karyawan_id, $cash->created_by);
        $this->assertStringContainsString($report->report_number, (string) $cash->description);

        $qris = FinancialTransaction::where('source_id', $report->id . '-QRIS')->firstOrFail();
        $this->assertSame(45000, $qris->amount);
        $this->assertSame('QRIS', $qris->paymentMethod?->name);
        $this->assertSame($category->id, $qris->category_id);

        $summary = app(KeuanganService::class)->summary(
            \Illuminate\Support\Carbon::parse('2026-10-01'),
            \Illuminate\Support\Carbon::parse('2026-10-31'),
            $this->express->cabang_id,
        );

        $this->assertSame(75000, $summary['income']);

        Livewire::test(ListFinancialTransactions::class)
            ->assertCanSeeTableRecords([$cash, $qris])
            ->filterTable('category_id', ['value' => $category->id])
            ->assertCanSeeTableRecords([$cash, $qris]);
    }

    public function test_approval_satu_metode_menghasilkan_satu_transaksi(): void
    {
        $this->actingAs($this->supervisor);

        $report = $this->makeReport($this->express, [
            ['payment_method' => 'QRIS', 'amount' => 25000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->supervisor);

        $this->assertSame(1, FinancialTransaction::count());
        $this->assertDatabaseHas('financial_transactions', [
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => $report->id . '-QRIS',
            'amount' => 25000,
        ]);
    }

    public function test_cabang_tanpa_akun_senama_tetap_dicatat_tanpa_akun(): void
    {
        $this->actingAs($this->supervisor);

        $report = $this->makeReport($this->unmappedBranch, [
            ['payment_method' => 'QRIS', 'amount' => 40000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->supervisor);

        $transaction = FinancialTransaction::firstOrFail();

        $this->assertNull($transaction->account_id);
        $this->assertSame('Booth Baru', $transaction->cabang?->nama_cabang);
        $this->assertSame(40000, $transaction->amount);
        $this->assertSame($this->pendapatanBoothCategory()->id, $transaction->category_id);
    }

    public function test_pencatatan_ulang_tidak_membuat_transaksi_ganda(): void
    {
        $this->actingAs($this->supervisor);

        $report = $this->makeReport($this->express, [
            ['payment_method' => 'CASH', 'amount' => 30000],
            ['payment_method' => 'QRIS', 'amount' => 45000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->supervisor);

        $created = app(SessionReportFinancialService::class)->record($report->fresh(), $this->supervisor);

        $this->assertSame(0, $created);
        $this->assertSame(2, FinancialTransaction::count());

        $this->expectException(RuntimeException::class);
        app(SessionWorkflowService::class)->approve($report->fresh(), $this->supervisor);
    }

    public function test_kategori_belum_tersedia_membatalkan_approval_dengan_pesan_jelas(): void
    {
        $this->actingAs($this->supervisor);

        FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->delete();

        $report = $this->makeReport($this->express, [
            ['payment_method' => 'QRIS', 'amount' => 25000],
        ]);

        try {
            app(SessionWorkflowService::class)->approve($report, $this->supervisor);

            $this->fail('Approval seharusnya gagal ketika kategori Pendapatan Booth belum tersedia.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(SessionReportFinancialService::CATEGORY_NAME, $exception->getMessage());
        }

        $this->assertSame(SessionReport::STATUS_WAITING_APPROVAL, $report->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('approval_logs', 0);
    }

    public function test_rekap_mode_manual_tanpa_nominal_tidak_membuat_transaksi(): void
    {
        $this->actingAs($this->supervisor);

        $wedding = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Wedding Photomate',
            'alamat' => 'Wedding Photomate Outlet',
        ]);

        $report = SessionReport::create([
            'report_date' => '2026-10-11',
            'cabang_id' => $wedding->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crew->karyawan_id,
            'wedding_total_sessions' => 150,
            'wedding_total_sheets' => 300,
        ]);

        $report->crews()->attach($this->crew->karyawan_id);

        app(SessionWorkflowService::class)->approve($report, $this->supervisor);

        $this->assertSame(SessionReport::STATUS_APPROVED, $report->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    protected function makeReport(Cabang $cabang, array $transactions): SessionReport
    {
        $report = SessionReport::create([
            'report_date' => '2026-10-11',
            'cabang_id' => $cabang->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crew->karyawan_id,
        ]);

        foreach ($transactions as $transaction) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => $transaction['payment_method'],
                'session_count' => 1,
                'amount' => $transaction['amount'],
            ]);
        }

        $report->crews()->attach($this->crew->karyawan_id);

        return $report;
    }

    protected function pendapatanBoothCategory(): FinancialCategory
    {
        return FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();
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
