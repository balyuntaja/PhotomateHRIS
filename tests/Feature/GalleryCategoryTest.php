<?php

namespace Tests\Feature;

use App\Filament\Resources\GalleryResource\Pages\CreateGallery;
use App\Models\Gallery;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GalleryCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $admin;

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

        $this->admin = $this->makeKaryawan('K0001', 'Admin', 'R01', 'admin@photomate.id', [
            'view_any_gallery',
            'view_gallery',
            'create_gallery',
            'update_gallery',
            'delete_gallery',
            'delete_any_gallery',
        ]);
    }

    public function test_kategori_bawaan_gallery_tetap_tersedia_walau_belum_ada_gallery(): void
    {
        $this->assertSame([
            'Wedding',
            'Event',
            'Brand',
            'High School Collaboration',
        ], array_keys(Gallery::categoryOptions()));
    }

    public function test_pilihan_kategori_menggabungkan_kategori_bawaan_dengan_kategori_yang_sudah_dipakai(): void
    {
        $this->makeGallery('Brand Photomate', 'Brand');
        $this->makeGallery('Wisuda SMK 1', 'Wisuda');

        $this->assertSame([
            'Wedding',
            'Event',
            'Brand',
            'High School Collaboration',
            'Wisuda',
        ], array_keys(Gallery::categoryOptions()));
    }

    public function test_nama_kategori_baru_disamakan_dengan_kategori_lama_yang_isinya_sama(): void
    {
        $this->makeGallery('Brand Photomate', 'Brand');

        $this->assertSame('Brand', Gallery::normalizeCategory('  brand '));
        $this->assertSame('High School Collaboration', Gallery::normalizeCategory('High   School   Collaboration'));
        $this->assertSame('Wisuda', Gallery::normalizeCategory('Wisuda'));
    }

    public function test_kategori_baru_dapat_ditambahkan_langsung_dari_form_gallery(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateGallery::class)
            ->assertSet('data.category', 'Event')
            ->callFormComponentAction('category', 'createOption', ['name' => 'Wisuda'])
            ->assertHasNoFormComponentActionErrors()
            ->assertSet('data.category', 'Wisuda');
    }

    public function test_kategori_baru_yang_isinya_sudah_ada_tidak_membuat_duplikat(): void
    {
        $this->actingAs($this->admin);

        $this->makeGallery('Brand Photomate', 'Brand');

        Livewire::test(CreateGallery::class)
            ->callFormComponentAction('category', 'createOption', ['name' => 'brand'])
            ->assertHasNoFormComponentActionErrors()
            ->assertSet('data.category', 'Brand');
    }

    public function test_nama_kategori_baru_wajib_diisi(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateGallery::class)
            ->callFormComponentAction('category', 'createOption', ['name' => ''])
            ->assertHasFormComponentActionErrors(['name' => 'required'])
            ->assertSet('data.category', 'Event');
    }

    public function test_user_tanpa_akses_tidak_dapat_membuka_cms_gallery(): void
    {
        $staff = $this->makeKaryawan('K0002', 'Karyawan', 'R07', 'staff@photomate.id', []);

        $this->actingAs($staff);

        $this->get('/admin/galleries')->assertForbidden();
    }

    public function test_gallery_baru_tersimpan_dengan_kategori_baru(): void
    {
        $this->makeGallery('Wisuda SMK 1', 'Wisuda');

        $this->assertDatabaseHas('galleries', [
            'title' => 'Wisuda SMK 1',
            'category' => 'Wisuda',
            'is_active' => true,
        ]);

        $this->assertContains('Wisuda', array_keys(Gallery::categoryOptions()));
    }

    protected function makeGallery(string $title, string $category): Gallery
    {
        return Gallery::create([
            'title' => $title,
            'category' => $category,
            'image' => 'galleries/contoh.webp',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function makeKaryawan(
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
            'alamat' => 'Jakarta',
        ]);

        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
