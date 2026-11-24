<?php

use App\Models\BoothSessionHppSetting;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = [
            'view_booth_hpp' => 'Melihat data HPP booth Janus dan Golio',
            'manage_booth_hpp' => 'Mengelola tarif HPP booth Janus dan Golio',
            'confirm_booth_hpp' => 'Mengonfirmasi HPP booth Janus dan Golio',
            'post_booth_hpp' => 'Memposting HPP booth ke sistem akuntansi',
            'reopen_booth_hpp' => 'Membuka kembali data HPP booth yang sudah dikonfirmasi',
        ];

        foreach ($permissions as $permName => $label) {
            Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => 'web',
            ]);
        }

        // Assign ke role Admin, Manager Finance, dan CEO
        $targetRoles = ['Admin', 'Manager Finance', 'CEO', 'Super Admin', 'super_admin'];
        foreach ($targetRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo(array_keys($permissions));
            }
        }

        // Seed default settings untuk Janus dan Golio bila belum ada
        if (DB::table('booth_session_hpp_settings')->where('booth_type', 'Janus')->doesntExist()) {
            DB::table('booth_session_hpp_settings')->insert([
                'booth_type' => 'Janus',
                'default_rate_per_sheet' => 2500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('booth_session_hpp_settings')->where('booth_type', 'Golio')->doesntExist()) {
            DB::table('booth_session_hpp_settings')->insert([
                'booth_type' => 'Golio',
                'default_rate_per_sheet' => 3000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissions = [
            'view_booth_hpp',
            'manage_booth_hpp',
            'confirm_booth_hpp',
            'post_booth_hpp',
            'reopen_booth_hpp',
        ];

        Permission::whereIn('name', $permissions)->delete();
        DB::table('booth_session_hpp_settings')->whereIn('booth_type', ['Janus', 'Golio'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
