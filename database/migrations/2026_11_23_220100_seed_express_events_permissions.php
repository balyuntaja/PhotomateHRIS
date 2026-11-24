<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view_express_events',
        'create_express_events',
        'update_express_events',
        'delete_express_events',
        'allocate_express_recaps',
        'reconcile_express_events',
        'manage_express_hpp',
        'confirm_express_hpp',
        'finalize_express_events',
        'reopen_express_events',
    ];

    public function up(): void
    {
        $roles = Role::whereIn('name', ['Admin', 'Manager Finance', 'CEO'])->get();

        foreach (self::PERMISSIONS as $permName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permName, 'guard_name' => 'web']
            );

            foreach ($roles as $role) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->permission_id,
                    'role_id' => $role->role_id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $perms = Permission::whereIn('name', self::PERMISSIONS)->get();

        foreach ($perms as $perm) {
            DB::table('role_has_permissions')->where('permission_id', $perm->permission_id)->delete();
            $perm->delete();
        }
    }
};
