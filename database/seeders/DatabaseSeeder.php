<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Urutan pemanggilan sangat penting untuk menjaga integritas relasi
        $this->call([

            PermissionSeeder::class,
            RoleSeeder::class,
            
            // 1. Buat data master yang tidak memiliki dependensi
            KategoriTerSeeder::class,      
            TarifTerSeeder::class,         
            GolonganPtkpSeeder::class,     
            
            // 2. Buat data perusahaan, cabang, dan semua karyawan terkait
            PerusahaanKaryawanSeeder::class,
            
            // 3. Buat data transaksional yang bergantung pada karyawan
            CutiSeeder::class,
            IzinSeeder::class,
            AbsensiSeeder::class,
            LemburSeeder::class,
        ]);

        // Seed default QR link if not exists
        \App\Models\QrLink::firstOrCreate([
            'slug' => 'main'
        ], [
            'title' => 'Photomate Official',
            'original_url' => 'https://photomate.id',
        ]);

        // Seed default queue event if not exists
        \App\Models\Event::firstOrCreate([
            'event_code' => 'DEMO-QUEUE'
        ], [
            'name' => 'Photomate Demo Event',
            'location' => 'Photomate Studio Malang',
            'date' => now(),
            'status' => 'OPEN',
        ]);
    }
}