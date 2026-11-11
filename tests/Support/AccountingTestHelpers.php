<?php

namespace Tests\Support;

use App\Models\ChartOfAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Permission;
use App\Models\Perusahaan;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait AccountingTestHelpers
{
    protected function setUpAccountingData(): void
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
    }

    protected function makeKaryawan(string $id, string $roleId = 'R07'): Karyawan
    {
        return Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($id), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Karyawan '.$id,
            'email' => strtolower($id).'@photomate.id',
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

    protected function accountingAccount(string $code): ChartOfAccount
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail();
    }

    protected function makeAccount(array $attributes = []): ChartOfAccount
    {
        static $sequence = 0;
        $sequence++;

        $type = $attributes['type'] ?? ChartOfAccount::TYPE_ASSET;
        $reportGroup = $attributes['report_group'] ?? (ChartOfAccount::reportGroupsForType($type)[0] ?? null);

        return ChartOfAccount::create(array_merge([
            'code' => '9'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'name' => 'Akun Uji '.$sequence,
            'type' => $type,
            'normal_balance' => ChartOfAccount::normalBalanceForType($type),
            'parent_id' => null,
            'report_group' => $reportGroup,
            'description' => null,
            'is_active' => true,
            'sort_order' => 900 + $sequence,
        ], $attributes));
    }

    protected function category(string $name, string $type): FinancialCategory
    {
        return FinancialCategory::query()
            ->where('name', $name)
            ->where('transaction_type', $type)
            ->firstOrFail();
    }

    protected function makeFinancialTransaction(array $attributes = []): FinancialTransaction
    {
        $category = $this->category('Kertas', FinancialCategory::TYPE_EXPENSE);

        return FinancialTransaction::create(array_merge([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 125000,
            'category_id' => $category->getKey(),
            'parent_category_id' => $category->parent_category_id,
            'account_id' => 1,
            'transaction_date' => '2026-10-12',
            'description' => 'Transaksi existing uji',
        ], $attributes));
    }
}
