<?php

namespace Tests\Feature;

use App\Filament\Pages\QueueDashboard;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\EventResource;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QueueAccessTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_karyawan_dapat_mengakses_menu_sistem_antrean(): void
    {
        $karyawan = $this->makeUser('K0001', 'Karyawan', 'R07', 'crew@photomate.id');

        $this->actingAs($karyawan);

        $this->assertTrue(QueueDashboard::canAccess());
        $this->assertTrue(EventResource::canAccess());
        $this->assertTrue(CustomerResource::canAccess());

        $this->assertTrue(Gate::forUser($karyawan)->allows('viewAny', Event::class));
        $this->assertTrue(Gate::forUser($karyawan)->allows('viewAny', Customer::class));

        $this->get('/admin/queue-dashboard')->assertOk();
        $this->get('/admin/events')->assertOk();
        $this->get('/admin/customers')->assertOk();
    }

    public function test_karyawan_dapat_membuka_operator_dashboard_antrean(): void
    {
        $karyawan = $this->makeUser('K0002', 'Karyawan', 'R07', 'crew2@photomate.id');

        $event = Event::create([
            'name' => 'Wedding Uji',
            'event_code' => 'WED-UJI',
            'location' => 'Malang',
            'date' => now()->toDateString(),
            'status' => 'OPEN',
        ]);

        $this->actingAs($karyawan)
            ->get('/admin/events/' . $event->id . '/operator')
            ->assertOk();
    }

    public function test_role_di_luar_daftar_tetap_tidak_dapat_mengakses_menu_antrean(): void
    {
        $managerFinance = $this->makeUser('K0003', 'Manager Finance', 'R04', 'manager.finance@photomate.id');

        $this->actingAs($managerFinance);

        $this->assertFalse(QueueDashboard::canAccess());
        $this->assertFalse(EventResource::canAccess());
        $this->assertFalse(CustomerResource::canAccess());

        $this->get('/admin/queue-dashboard')->assertForbidden();
        $this->get('/admin/events')->assertForbidden();
        $this->get('/admin/customers')->assertForbidden();
    }

    protected function makeUser(string $karyawanId, string $roleName, string $roleId, string $email): Karyawan
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['role_id' => $roleId]);

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
            'alamat' => 'Jakarta',
        ]);

        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
