<?php

namespace Tests\Feature;

use App\Filament\Resources\CabangResource\Pages\CreateCabang;
use App\Filament\Resources\CabangResource\Pages\EditCabang;
use App\Filament\Resources\CabangResource\Pages\ViewCabang;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CabangManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $admin;
    protected Perusahaan $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup role & perusahaan
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);

        $perms = [
            'P0101' => 'view_any_cabang',
            'P0102' => 'create_cabang',
            'P0103' => 'view_cabang',
            'P0104' => 'update_cabang',
            'P0105' => 'delete_cabang',
        ];

        foreach ($perms as $id => $name) {
            $p = \App\Models\Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['permission_id' => $id]
            );
            $role->givePermissionTo($p);
        }

        $this->perusahaan = Perusahaan::firstOrCreate(
            ['perusahaan_id' => 'P01'],
            [
                'nama_perusahaan' => 'Photomate Indonesia',
                'email' => 'info@photomate.id',
                'nomor_telepon' => '08123456789',
                'jam_masuk' => '08:00',
                'jam_pulang' => '17:00',
            ]
        );

        $this->admin = Karyawan::firstOrCreate(
            ['karyawan_id' => 'ADM01'],
            [
                'role_id' => 'R01',
                'perusahaan_id' => 'P01',
                'nik' => '1234567890123499',
                'nama_lengkap' => 'Admin User',
                'email' => 'admin@photomate.id',
                'password' => bcrypt('password'),
                'tanggal_lahir' => '1990-01-01',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Jakarta',
            ]
        );
        $this->admin->assignRole('Admin');
    }

    public function test_create_cabang_form_does_not_contain_coordinates_or_radius(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateCabang::class)
            ->assertSuccessful()
            ->assertFormFieldExists('perusahaan_id')
            ->assertFormFieldExists('nama_cabang')
            ->assertFormFieldExists('alamat')
            ->assertFormFieldDoesNotExist('latitude')
            ->assertFormFieldDoesNotExist('longitude')
            ->assertFormFieldDoesNotExist('radius_lokasi');
    }

    public function test_can_create_cabang_without_alamat_and_coordinates(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateCabang::class)
            ->fillForm([
                'perusahaan_id' => $this->perusahaan->perusahaan_id,
                'nama_cabang' => 'Cabang Baru Tanpa Alamat',
                'alamat' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('cabang', [
            'nama_cabang' => 'Cabang Baru Tanpa Alamat',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'alamat' => null,
            'latitude' => null,
            'longitude' => null,
            'radius_lokasi' => null,
        ]);
    }

    public function test_view_cabang_renders_properly_with_null_coordinates_and_alamat(): void
    {
        $this->actingAs($this->admin);

        $cabang = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Cabang Minimalis',
            'alamat' => null,
            'latitude' => null,
            'longitude' => null,
            'radius_lokasi' => null,
        ]);

        Livewire::test(ViewCabang::class, [
            'record' => $cabang->getKey(),
        ])
            ->assertSuccessful()
            ->assertSee('Cabang Minimalis');
    }
}
