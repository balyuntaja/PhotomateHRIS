<?php

namespace Tests\Support;

use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\Penggajian;
use App\Models\Permission;
use App\Models\Perusahaan;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

trait DocumentSecurityTestHelpers
{
    protected function setUpDocumentSecurityData(): void
    {
        Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        Role::firstOrCreate(['role_id' => 'R01'], ['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['role_id' => 'R07'], ['name' => 'Karyawan', 'guard_name' => 'web']);

        $this->registerSqliteCompatibilityFunctions();
    }

    protected function makeKaryawan(string $id, string $roleId = 'R07'): Karyawan
    {
        return Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($id), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Karyawan ' . $id,
            'email' => strtolower($id) . '@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
            'jabatan' => 'Staff',
            'departemen' => 'Operasional',
            'gaji_pokok' => 5000000,
            'tunjangan_jabatan' => 1000000,
            'tunjangan_makan_bulanan' => 500000,
            'tunjangan_transport_bulanan' => 300000,
        ]);
    }

    protected function grantPermission(Karyawan $user, string $permissionName): void
    {
        $permission = Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);

        $user->givePermissionTo($permission);
        $user->unsetRelation('permissions')->unsetRelation('roles');
        $user->refresh();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makePenggajian(string $karyawanId, array $attributes = []): Penggajian
    {
        static $sequence = 0;
        $sequence++;

        return Penggajian::create(array_merge([
            'penggajian_id' => 'PG' . str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
            'periode_bulan' => 10,
            'periode_tahun' => 2026,
            'status_penggajian' => 'Disetujui',
            'karyawan_id' => $karyawanId,
            'sudah_ditransfer' => true,
            'gaji_pokok' => 5000000,
            'total_tunjangan' => 1800000,
            'total_lembur' => 0,
            'penghasilan_bruto' => 6800000,
            'potongan_alfa' => 0,
            'potongan_terlambat' => 0,
            'potongan_bpjs' => 0,
            'potongan_pph21' => 0,
            'penyesuaian' => 0,
            'total_potongan' => 0,
            'gaji_bersih' => 6800000,
        ], $attributes));
    }

    protected function makeInvoice(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_name' => 'Keluarga Uji',
            'event_name' => 'Wedding Uji',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Uji',
            'event_date' => '2026-10-12',
            'event_end_date' => '2026-10-12',
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-10-05',
            'subtotal' => 2599000,
            'discount' => 0,
            'total' => 2599000,
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
            'notes' => null,
        ], $attributes));
    }

    protected function assertPdfResponse($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $response->headers->get('content-type')
        );
    }

    /**
     * AbsensiService memakai fungsi MySQL TIME_TO_SEC/SEC_TO_TIME.
     * Daftarkan padanannya agar pengujian tetap berjalan di SQLite.
     */
    private function registerSqliteCompatibilityFunctions(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = $connection->getPdo();

        $pdo->sqliteCreateFunction('TIME_TO_SEC', function ($value): int {
            if ($value === null) {
                return 0;
            }

            $parts = array_map('intval', explode(':', (string) $value));

            return ($parts[0] ?? 0) * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
        }, 1);

        $pdo->sqliteCreateFunction('SEC_TO_TIME', function ($value): string {
            $seconds = (int) ($value ?? 0);

            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }, 1);
    }
}
