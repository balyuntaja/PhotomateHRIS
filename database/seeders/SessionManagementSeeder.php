<?php

namespace Database\Seeders;

use App\Models\PricingSetting;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SessionManagementSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Default Pricing Rule exists
        PricingSetting::firstOrCreate(
            ['is_active' => true],
            [
                'name' => 'Standard Pricing (Photomate)',
                'base_price' => 30000,
                'additional_session_price' => 15000,
                'effective_from' => now()->startOfYear(),
                'is_active' => true,
            ]
        );

        // 2. Ensure Supervisor role exists
        $supervisorRole = Role::where('name', 'Supervisor')->first();
        if (!$supervisorRole) {
            Role::create([
                'role_id' => 'R08',
                'name' => 'Supervisor',
                'guard_name' => 'web',
            ]);
        }
    }
}
