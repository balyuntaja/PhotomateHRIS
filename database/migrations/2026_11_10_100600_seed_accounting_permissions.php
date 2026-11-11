<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view_any_chart_of_account',
        'view_chart_of_account',
        'create_chart_of_account',
        'update_chart_of_account',
        'delete_chart_of_account',

        'view_any_category_account_mapping',
        'view_category_account_mapping',
        'create_category_account_mapping',
        'update_category_account_mapping',
        'delete_category_account_mapping',

        'view_any_journal_entry',
        'view_journal_entry',
        'create_journal_entry',
        'update_journal_entry',
        'delete_journal_entry',
        'post_journal_entry',
        'reverse_journal_entry',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
