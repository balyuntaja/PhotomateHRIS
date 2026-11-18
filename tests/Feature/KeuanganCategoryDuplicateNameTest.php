<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialCategoryResource\Pages\ListFinancialCategories;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KeuanganCategoryDuplicateNameTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $admin;

    protected FinancialParentCategory $parentOperasional;

    protected FinancialParentCategory $parentSdm;

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

        Role::firstOrCreate(['role_id' => 'R01'], ['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = Karyawan::create([
            'karyawan_id' => 'K0001',
            'role_id' => 'R01',
            'perusahaan_id' => 'P0001',
            'nik' => '123456789012',
            'nama_lengkap' => 'Admin Keuangan',
            'email' => 'admin@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $this->parentOperasional = FinancialParentCategory::firstOrCreate(
            ['name' => 'Operasional'],
            ['is_active' => true]
        );

        $this->parentSdm = FinancialParentCategory::firstOrCreate(
            ['name' => 'SDM'],
            ['is_active' => true]
        );
    }

    public function test_sub_kategori_bisa_memiliki_nama_yang_sama_pada_kategori_utama_yang_berbeda(): void
    {
        $this->actingAs($this->admin);

        // 1. Buat sub kategori 'Lain-lain' di bawah kategori utama 'Operasional'
        Livewire::test(ListFinancialCategories::class)
            ->mountAction('create')
            ->setActionData([
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'parent_category_id' => $this->parentOperasional->id,
                'name' => 'Lain-lain',
                'is_active' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('financial_categories', [
            'name' => 'Lain-lain',
            'parent_category_id' => $this->parentOperasional->id,
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
        ]);

        // 2. Buat sub kategori 'Lain-lain' di bawah kategori utama 'SDM' (kategori utama berbeda) -> HARUS BERHASIL
        Livewire::test(ListFinancialCategories::class)
            ->mountAction('create')
            ->setActionData([
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'parent_category_id' => $this->parentSdm->id,
                'name' => 'Lain-lain',
                'is_active' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('financial_categories', [
            'name' => 'Lain-lain',
            'parent_category_id' => $this->parentSdm->id,
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
        ]);

        // Pastikan keduanya tersimpan di database dengan ID berbeda pada masing-masing parent
        $categories = FinancialCategory::where('name', 'Lain-lain')
            ->whereIn('parent_category_id', [$this->parentOperasional->id, $this->parentSdm->id])
            ->get();
        $this->assertCount(2, $categories);
        $this->assertNotSame($categories[0]->parent_category_id, $categories[1]->parent_category_id);
    }

    public function test_sub_kategori_menolak_nama_duplikat_pada_kategori_utama_yang_sama(): void
    {
        $this->actingAs($this->admin);

        // Sub kategori pertama
        FinancialCategory::create([
            'name' => 'Bonus',
            'parent_category_id' => $this->parentSdm->id,
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        // Mencoba input nama yang sama pada kategori utama yang sama -> HARUS DITOLAK
        Livewire::test(ListFinancialCategories::class)
            ->mountAction('create')
            ->setActionData([
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'parent_category_id' => $this->parentSdm->id,
                'name' => 'Bonus',
                'is_active' => true,
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['name' => 'unique']);
    }

    public function test_edit_sub_kategori_bisa_mempertahankan_nama_tanpa_error_unique(): void
    {
        $this->actingAs($this->admin);

        $category = FinancialCategory::create([
            'name' => 'Transportasi Khusus',
            'parent_category_id' => $this->parentOperasional->id,
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        Livewire::test(ListFinancialCategories::class)
            ->mountTableAction('edit', $category)
            ->setTableActionData([
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'parent_category_id' => $this->parentOperasional->id,
                'name' => 'Transportasi Khusus',
                'is_active' => false,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('financial_categories', [
            'id' => $category->id,
            'name' => 'Transportasi Khusus',
            'is_active' => false,
        ]);
    }
}
