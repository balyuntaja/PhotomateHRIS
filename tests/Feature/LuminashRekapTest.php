<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Permission;
use App\Models\Role;
use App\Services\KeuanganService;
use App\Services\LuminashImportService;
use App\Services\LuminashRekapService;
use App\Services\SessionReportFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LuminashRekapTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $managerFinance;

    protected FinancialCategory $category;

    protected FinancialAccount $golioAccount;

    protected FinancialPaymentMethod $qris;

    protected Cabang $golio;

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

        $this->golioAccount = FinancialAccount::where('name', LuminashImportService::ACCOUNT_NAME)->firstOrFail();

        $this->qris = FinancialPaymentMethod::where('name', 'QRIS')->firstOrFail();

        $this->golio = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => LuminashImportService::CABANG_NAME,
            'alamat' => 'Golio Booth',
        ]);

        $this->managerFinance = $this->makeUser('K0001', 'Manager Finance', 'R04', 'manager.finance@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'view_keuangan_report',
        ]);
    }

    public function test_transaksi_tanggal_sama_direkap_menjadi_satu_baris(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('lima.csv', [
            $this->csvRow('ORD-1', '13 Oct 2026 21:30', '100000.0'),
            $this->csvRow('ORD-2', '13 Oct 2026 21:31', '75000.0'),
            $this->csvRow('ORD-3', '13 Oct 2026 21:32', '50000.0'),
            $this->csvRow('ORD-4', '13 Oct 2026 21:33', '125000.0'),
            $this->csvRow('ORD-5', '13 Oct 2026 21:34', '80000.0'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance, 'lima.csv');

        $rekap = $this->rekapFor('2026-10-13');

        $this->assertNotNull($rekap);
        $this->assertSame(430000, $rekap->amount);
        $this->assertSame(FinancialTransaction::TYPE_INCOME, $rekap->transaction_type);
        $this->assertSame(FinancialTransaction::PAYMENT_STATUS_LUNAS, $rekap->payment_status);
        $this->assertSame($this->category->id, $rekap->category_id);
        $this->assertSame($this->golioAccount->id, $rekap->account_id);
        $this->assertSame($this->golio->cabang_id, $rekap->cabang_id);
        $this->assertSame('Rekap Pendapatan Booth Luminash (5 transaksi)', $rekap->description);

        // Hanya satu baris aktif pada tanggal tersebut.
        $this->assertSame(1, FinancialTransaction::whereDate('transaction_date', '2026-10-13')->count());

        // Transaksi per-order tetap tersimpan sebagai komponen (soft delete).
        $this->assertSame(5, FinancialTransaction::withTrashed()
            ->whereDate('transaction_date', '2026-10-13')
            ->where('id', '!=', $rekap->id)
            ->count());

        // Total tetap konsisten dengan laporan.
        $summary = app(KeuanganService::class)->summary(
            \Illuminate\Support\Carbon::parse('2026-10-01'),
            \Illuminate\Support\Carbon::parse('2026-10-31'),
            $this->golio->cabang_id,
        );
        $this->assertSame(430000, $summary['income']);
    }

    public function test_tanggal_berbeda_menghasilkan_rekap_terpisah(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('dua-hari.csv', [
            $this->csvRow('ORD-A', '11 Oct 2026 21:30', '25000.0'),
            $this->csvRow('ORD-B', '11 Oct 2026 19:22', '40000.0'),
            $this->csvRow('ORD-C', '10 Oct 2026 22:55', '25000.0'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance, 'dua-hari.csv');

        $this->assertSame(65000, $this->rekapFor('2026-10-11')?->amount);
        $this->assertSame(25000, $this->rekapFor('2026-10-10')?->amount);
        $this->assertSame(2, FinancialTransaction::where('source_type', LuminashImportService::SOURCE)->count());
    }

    public function test_impor_ulang_tidak_menambah_rekap_atau_nominal(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('ulang.csv', [
            $this->csvRow('ORD-1', '11 Oct 2026 21:30', '25000.0'),
            $this->csvRow('ORD-2', '11 Oct 2026 21:16', '40000.0'),
        ]);

        $service = app(LuminashImportService::class);
        $service->import($path, 'local', $this->managerFinance);
        $service->import($path, 'local', $this->managerFinance);

        $this->assertSame(65000, $this->rekapFor('2026-10-11')?->amount);
        $this->assertSame(1, FinancialTransaction::whereDate('transaction_date', '2026-10-11')->count());
    }

    public function test_transaksi_baru_pada_tanggal_yang_sama_memperbarui_total_rekap(): void
    {
        $this->actingAs($this->managerFinance);

        $service = app(LuminashImportService::class);

        $first = $this->storeCsv('bagian-1.csv', [
            $this->csvRow('ORD-1', '11 Oct 2026 21:30', '25000.0'),
        ]);

        $second = $this->storeCsv('bagian-2.csv', [
            $this->csvRow('ORD-2', '11 Oct 2026 21:16', '40000.0'),
        ]);

        $service->import($first, 'local', $this->managerFinance);
        $this->assertSame(25000, $this->rekapFor('2026-10-11')?->amount);

        $service->import($second, 'local', $this->managerFinance);

        $rekap = $this->rekapFor('2026-10-11');

        $this->assertSame(65000, $rekap?->amount);
        $this->assertSame('Rekap Pendapatan Booth Luminash (2 transaksi)', $rekap?->description);
        $this->assertSame(1, FinancialTransaction::whereDate('transaction_date', '2026-10-11')->count());
    }

    public function test_rincian_komponen_rekap_menjumlah_sesuai_total(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('rincian.csv', [
            $this->csvRow('ORD-1', '11 Oct 2026 21:30', '25000.0'),
            $this->csvRow('ORD-2', '11 Oct 2026 21:16', '40000.0'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $rekap = $this->rekapFor('2026-10-11');
        $components = app(LuminashRekapService::class)->constituents($rekap);

        $this->assertSame(2, $components->count());
        $this->assertSame($rekap->amount, (int) $components->sum('amount'));
    }

    public function test_transaksi_manual_dan_cabang_lain_tidak_terpengaruh(): void
    {
        $this->actingAs($this->managerFinance);

        $manual = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 999000,
            'category_id' => $this->category->id,
            'transaction_date' => '2026-10-11',
            'description' => 'Transaksi manual',
        ]);

        $path = $this->storeCsv('golio.csv', [
            $this->csvRow('ORD-1', '11 Oct 2026 21:30', '25000.0'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $this->assertNull($manual->fresh()->deleted_at);
        $this->assertSame(25000, $this->rekapFor('2026-10-11')?->amount);
    }

    public function test_backfill_mengonsolidasi_transaksi_luminash_lama(): void
    {
        $this->actingAs($this->managerFinance);

        foreach ([['ORD-1', 25000], ['ORD-2', 40000], ['ORD-3', 35000]] as [$orderId, $amount]) {
            FinancialTransaction::create([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => $amount,
                'category_id' => $this->category->id,
                'account_id' => $this->golioAccount->id,
                'cabang_id' => $this->golio->cabang_id,
                'payment_method_id' => $this->qris->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-10-11',
                'description' => 'Luminash lama ' . $orderId,
                'source_type' => LuminashImportService::SOURCE,
                'source_id' => $orderId,
            ]);
        }

        // Dry-run tidak menyimpan apa pun.
        $this->artisan('keuangan:consolidate-luminash-rekap', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($this->rekapFor('2026-10-11'));
        $this->assertSame(3, FinancialTransaction::whereDate('transaction_date', '2026-10-11')->count());

        // Jalankan sungguhan.
        $this->artisan('keuangan:consolidate-luminash-rekap')->assertSuccessful();

        $rekap = $this->rekapFor('2026-10-11');

        $this->assertNotNull($rekap);
        $this->assertSame(100000, $rekap->amount);
        $this->assertSame(1, FinancialTransaction::whereDate('transaction_date', '2026-10-11')->count());

        // Idempoten: dijalankan ulang tidak mengubah apa pun.
        $this->artisan('keuangan:consolidate-luminash-rekap')->assertSuccessful();

        $this->assertSame(100000, $this->rekapFor('2026-10-11')?->amount);
        $this->assertSame(3, FinancialTransaction::withTrashed()
            ->whereDate('transaction_date', '2026-10-11')
            ->where('id', '!=', $rekap->id)
            ->count());
    }

    public function test_halaman_transaksi_menampilkan_baris_rekap(): void
    {
        $this->actingAs($this->managerFinance);

        $path = $this->storeCsv('halaman.csv', [
            $this->csvRow('ORD-1', '11 Oct 2026 21:30', '25000.0'),
            $this->csvRow('ORD-2', '11 Oct 2026 21:16', '40000.0'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->managerFinance);

        $rekap = $this->rekapFor('2026-10-11');

        Livewire::test(ListFinancialTransactions::class)
            ->assertCanSeeTableRecords([$rekap])
            ->assertSee('Rekap Pendapatan Booth Luminash (2 transaksi)');

        $this->assertTrue(app(LuminashRekapService::class)->isRekap($rekap));
    }

    protected function rekapFor(string $date): ?FinancialTransaction
    {
        return FinancialTransaction::where('source_type', LuminashImportService::SOURCE)
            ->where('source_id', (new LuminashRekapService)->rekapSourceId($date))
            ->first();
    }

    protected function storeCsv(string $name, array $rows): string
    {
        $path = 'luminash-imports/' . $name;
        Storage::disk('local')->put($path, $this->csvContent($rows));

        return $path;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function csvContent(array $rows): string
    {
        $headers = [
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
     * @return array<int, string>
     */
    protected function csvRow(string $orderId, string $timestamp, string $amount): array
    {
        return [
            $orderId, $timestamp, 'BOOTH_1', 'QRIS', 'qris', $amount,
            '1', '0', 'Complete', 'Golio 1', '-', '', 'No',
            'https://media.luminash.id/session/' . $orderId,
        ];
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
