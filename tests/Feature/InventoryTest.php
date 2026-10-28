<?php

namespace Tests\Feature;

use App\Filament\Pages\InventoryOverview;
use App\Filament\Pages\InventoryStocks;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryItemResource\Pages\CreateInventoryItem;
use App\Filament\Resources\InventoryItemResource\Pages\EditInventoryItem;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryItemResource\Pages\ViewInventoryItem;
use App\Filament\Resources\InventoryItemResource\RelationManagers\StocksRelationManager;
use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\InventoryTransactionResource\Pages\CreateInventoryTransaction;
use App\Filament\Resources\InventoryTransactionResource\Pages\ListInventoryTransactions;
use App\Models\Cabang;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use App\Utils\InventoryBranches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $admin;

    protected Karyawan $crew;

    protected Karyawan $supervisor;

    protected Cabang $branchA;

    protected Cabang $branchB;

    protected Cabang $expressBranch;

    protected Cabang $expressSewa;

    protected Cabang $expressSelfRun;

    protected InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);
        Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'], ['role_id' => 'R09']);

        Perusahaan::firstOrCreate(
            ['perusahaan_id' => 'P01'],
            [
                'nama_perusahaan' => 'Photomate Indonesia',
                'email' => 'info@photomate.id',
                'nomor_telepon' => '08123456789',
                'jam_masuk' => '08:00',
                'jam_pulang' => '17:00',
            ],
        );

        $this->admin = $this->makeUser('ADM01', 'Admin Inventory', 'R01', 'admin.inventory@photomate.id', '9000000000000001', 'Admin');
        $this->crew = $this->makeUser('KRY01', 'Karyawan Biasa', 'R07', 'crew.inventory@photomate.id', '9000000000000002', 'Karyawan');
        $this->supervisor = $this->makeUser('SPV01', 'Supervisor Inventory', 'R08', 'spv.inventory@photomate.id', '9000000000000003', 'Supervisor');

        $this->branchA = $this->makeBranch('C0001', 'Golio');
        $this->branchB = $this->makeBranch('C0002', 'Newspaper Janus');
        $this->expressBranch = $this->makeBranch('C0003', 'Photomate Express');
        $this->expressSewa = $this->makeBranch('C0004', 'Express Sewa');
        $this->expressSelfRun = $this->makeBranch('C0005', 'Express Self Run');

        $this->service = app(InventoryService::class);
        $this->actingAs($this->admin);
    }

    public function test_admin_can_create_item_and_stock_rows_are_initialized_per_branch(): void
    {
        Livewire::test(CreateInventoryItem::class)
            ->fillForm([
                'kode_barang' => 'KRT-4R',
                'nama_barang' => 'Kertas Foto 4R',
                'kategori' => 'Kertas Foto',
                'satuan' => 'rim',
                'min_stok_default' => 8,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = InventoryItem::query()->where('kode_barang', 'KRT-4R')->firstOrFail();

        $this->assertSame(3, $item->stocks()->count());
        $this->assertSame(0, (int) $item->stocks()->sum('jumlah'));
        $this->assertSame(8, (int) $item->stocks()->value('min_stok'));
        $this->assertSame('habis', $item->stock_status);
    }

    public function test_admin_can_edit_item_and_deactivate_it(): void
    {
        $item = $this->makeItem();

        Livewire::test(EditInventoryItem::class, ['record' => $item->getRouteKey()])
            ->fillForm([
                'kode_barang' => $item->kode_barang,
                'nama_barang' => 'Kertas Foto 2R Premium',
                'kategori' => 'Kertas Foto',
                'satuan' => 'rim',
                'min_stok_default' => 7,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Kertas Foto 2R Premium', $item->fresh()->nama_barang);
        $this->assertSame(7, (int) $item->fresh()->min_stok_default);

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('nonaktifkan', $item->fresh())
            ->assertHasNoTableActionErrors();

        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_branch_minimum_stock_can_be_set_from_relation_manager(): void
    {
        $item = $this->makeItem(minStock: 5);
        $stock = $item->stocks()
            ->where('cabang_id', $this->branchA->cabang_id)
            ->firstOrFail();

        Livewire::test(StocksRelationManager::class, [
            'ownerRecord' => $item,
            'pageClass' => ViewInventoryItem::class,
        ])
            ->callTableAction('atur_minimum', $stock, ['min_stok' => 9])
            ->assertHasNoTableActionErrors();

        $this->assertSame(9, $stock->fresh()->min_stok);
        $this->assertSame(5, (int) $item->fresh()->min_stok_default);
    }

    public function test_stock_transactions_update_only_the_selected_branch_and_record_history(): void
    {
        $item = $this->makeItem();

        $incoming = $this->record(InventoryTransaction::TYPE_IN, 10, $item, $this->branchA, null, 'Pembelian awal');

        $this->assertSame(10, $this->stockOf($item, $this->branchA));
        $this->assertSame(0, $this->stockOf($item, $this->branchB));
        $this->assertSame(0, $incoming->stok_sebelum);
        $this->assertSame(10, $incoming->stok_sesudah);
        $this->assertSame(10, $incoming->jumlah);
        $this->assertSame($this->admin->karyawan_id, $incoming->user_id);
        $this->assertSame('Admin Inventory', $incoming->user_name);
        $this->assertNotEmpty($incoming->nomor_transaksi);

        $outgoing = $this->record(InventoryTransaction::TYPE_OUT, 3, $item, $this->branchA, null, 'Pemakaian operasional');

        $this->assertSame(7, $this->stockOf($item, $this->branchA));
        $this->assertSame(10, $outgoing->stok_sebelum);
        $this->assertSame(7, $outgoing->stok_sesudah);
        $this->assertSame(3, $outgoing->jumlah_keluar);

        $adjustment = $this->record(
            InventoryTransaction::TYPE_ADJUSTMENT,
            null,
            $item,
            $this->branchA,
            4,
            'Hasil stock opname',
        );

        $this->assertSame(4, $this->stockOf($item, $this->branchA));
        $this->assertSame(7, $adjustment->stok_sebelum);
        $this->assertSame(4, $adjustment->stok_sesudah);
        $this->assertSame(3, $adjustment->jumlah);
        $this->assertSame('Hasil stock opname', $adjustment->alasan);
        $this->assertSame(0, $adjustment->jumlah_masuk);
        $this->assertSame(3, $adjustment->jumlah_keluar);

        $this->assertSame(3, InventoryTransaction::query()->count());
    }

    public function test_out_transaction_cannot_make_stock_negative_and_leaves_no_partial_changes(): void
    {
        $item = $this->makeItem();
        $this->record(InventoryTransaction::TYPE_IN, 5, $item, $this->branchA);

        $transactionCount = InventoryTransaction::query()->count();

        try {
            $this->record(InventoryTransaction::TYPE_OUT, 6, $item, $this->branchA);
            $this->fail('Transaksi keluar melebihi stok seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('jumlah', $exception->errors());
        }

        $this->assertSame(5, $this->stockOf($item, $this->branchA));
        $this->assertSame($transactionCount, InventoryTransaction::query()->count());
    }

    public function test_adjustment_requires_reason_and_rejects_zero_difference(): void
    {
        $item = $this->makeItem();
        $this->record(InventoryTransaction::TYPE_IN, 6, $item, $this->branchA);

        try {
            $this->record(InventoryTransaction::TYPE_ADJUSTMENT, null, $item, $this->branchA, 4);
            $this->fail('Penyesuaian tanpa alasan seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('alasan', $exception->errors());
        }

        try {
            $this->record(InventoryTransaction::TYPE_ADJUSTMENT, null, $item, $this->branchA, 6, 'Tidak ada perubahan');
            $this->fail('Penyesuaian tanpa selisih seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stok_aktual', $exception->errors());
        }

        $this->assertSame(6, $this->stockOf($item, $this->branchA));
        $this->assertSame(1, InventoryTransaction::query()->count());
    }

    public function test_idempotency_key_prevents_duplicate_stock_processing(): void
    {
        $item = $this->makeItem();
        $key = (string) Str::uuid();

        $first = $this->record(InventoryTransaction::TYPE_IN, 10, $item, $this->branchA, null, 'Pembelian', $key);
        $second = $this->record(InventoryTransaction::TYPE_IN, 10, $item, $this->branchA, null, 'Pembelian', $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(10, $this->stockOf($item, $this->branchA));
        $this->assertSame(1, InventoryTransaction::query()->count());

        try {
            $this->record(InventoryTransaction::TYPE_OUT, 1, $item, $this->branchA, null, 'Payload berbeda', $key);
            $this->fail('Kunci idempotensi yang dipakai ulang dengan payload berbeda seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertSame(10, $this->stockOf($item, $this->branchA));
    }

    public function test_transaction_detail_modal_renders_from_history(): void
    {
        $item = $this->makeItem();
        $transaction = $this->record(InventoryTransaction::TYPE_IN, 5, $item, $this->branchA, null, 'Stok awal');

        Livewire::test(ListInventoryTransactions::class)
            ->mountTableAction('view', $transaction)
            ->assertSee('Detail Transaksi');
    }

    public function test_inactive_item_cannot_be_used_for_new_transaction(): void
    {
        $item = $this->makeItem();
        $this->record(InventoryTransaction::TYPE_IN, 3, $item, $this->branchA);

        $item->update(['is_active' => false]);

        try {
            $this->record(InventoryTransaction::TYPE_IN, 2, $item, $this->branchA);
            $this->fail('Barang nonaktif seharusnya tidak dapat ditransaksikan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_item_id', $exception->errors());
        }

        $this->assertSame(3, $this->stockOf($item, $this->branchA));
        $this->assertSame(1, InventoryTransaction::query()->count());
    }

    public function test_stock_status_detects_safe_low_and_out(): void
    {
        $item = $this->makeItem(minStock: 5);
        $stock = InventoryStock::query()
            ->where('inventory_item_id', $item->id)
            ->where('cabang_id', $this->branchA->cabang_id)
            ->firstOrFail();

        $this->assertSame('habis', $stock->status);
        $this->assertSame('habis', $item->fresh()->stock_status);

        $this->record(InventoryTransaction::TYPE_IN, 6, $item, $this->branchA);
        $this->assertSame('aman', $stock->fresh()->status);
        $this->assertSame('aman', $item->fresh()->stock_status);

        $this->record(InventoryTransaction::TYPE_OUT, 1, $item, $this->branchA);
        $this->assertSame('menipis', $stock->fresh()->status);
        $this->assertSame('menipis', $item->fresh()->stock_status);

        $this->record(InventoryTransaction::TYPE_OUT, 5, $item, $this->branchA);
        $this->assertSame('habis', $stock->fresh()->status);
        $this->assertSame('habis', $item->fresh()->stock_status);
    }

    public function test_summary_and_attention_query_respect_branch_filter(): void
    {
        $lowItem = $this->makeItem('KRT-2R', 'Kertas Foto 2R');
        $safeItem = $this->makeItem('TINTA-01', 'Tinta Printer', 2);
        $outItem = $this->makeItem('PAPER-FRAME', 'Paper Frame', 2);

        $this->record(InventoryTransaction::TYPE_IN, 2, $lowItem, $this->branchA);
        $this->record(InventoryTransaction::TYPE_IN, 10, $safeItem, $this->branchA);

        $all = $this->service->summary();
        $this->assertSame(3, $all['total_items']);
        $this->assertSame(12, $all['total_stock']);
        $this->assertSame(1, $all['low_stock']);
        $this->assertSame(1, $all['out_of_stock']);

        $branchA = $this->service->summary($this->branchA->cabang_id);
        $this->assertSame(3, $branchA['total_items']);
        $this->assertSame(12, $branchA['total_stock']);
        $this->assertSame(1, $branchA['low_stock']);
        $this->assertSame(1, $branchA['out_of_stock']);

        $this->assertSame(2, $this->service->attentionQuery($this->branchA->cabang_id)->count());
        $this->assertSame(8, $this->service->attentionQuery()->count());
    }

    public function test_inventory_branch_options_only_show_three_main_branches(): void
    {
        $this->assertSame(
            [
                $this->expressBranch->cabang_id => 'Express',
                $this->branchA->cabang_id => 'Golio',
                $this->branchB->cabang_id => 'Newspaper Janus',
            ],
            InventoryBranches::options(),
        );
    }

    public function test_express_variant_stocks_are_aggregated_into_one_branch_without_duplication(): void
    {
        $item = $this->makeItem();

        $this->record(InventoryTransaction::TYPE_IN, 4, $item, $this->expressBranch);
        $this->record(InventoryTransaction::TYPE_IN, 7, $item, $this->branchA);
        $this->record(InventoryTransaction::TYPE_IN, 9, $item, $this->branchB);

        InventoryStock::create([
            'inventory_item_id' => $item->id,
            'cabang_id' => $this->expressSewa->cabang_id,
            'jumlah' => 6,
            'min_stok' => 5,
            'last_transaction_at' => now(),
        ]);

        InventoryStock::create([
            'inventory_item_id' => $item->id,
            'cabang_id' => $this->expressSelfRun->cabang_id,
            'jumlah' => 10,
            'min_stok' => 5,
            'last_transaction_at' => now(),
        ]);

        $expressStock = $this->service->stockQuery($this->expressBranch->cabang_id);

        $this->assertSame(1, (clone $expressStock)->count());
        $this->assertSame(20, (int) (clone $expressStock)->sum('jumlah'));

        $expressSummary = $this->service->summary($this->expressBranch->cabang_id);
        $this->assertSame(1, $expressSummary['total_items']);
        $this->assertSame(20, $expressSummary['total_stock']);

        $golioSummary = $this->service->summary($this->branchA->cabang_id);
        $this->assertSame(7, $golioSummary['total_stock']);

        $janusSummary = $this->service->summary($this->branchB->cabang_id);
        $this->assertSame(9, $janusSummary['total_stock']);

        $item->refresh();
        $this->assertSame(36, $item->total_stock);
        $this->assertSame(3, $item->branch_stock_count);

        $this->assertSame(
            36,
            (int) InventoryStock::query()->where('inventory_item_id', $item->id)->sum('jumlah'),
        );
    }

    public function test_express_summary_does_not_count_the_same_item_multiple_times(): void
    {
        $item = $this->makeItem();

        $this->record(InventoryTransaction::TYPE_IN, 4, $item, $this->expressBranch);

        foreach ([$this->expressSewa, $this->expressSelfRun] as $branch) {
            InventoryStock::create([
                'inventory_item_id' => $item->id,
                'cabang_id' => $branch->cabang_id,
                'jumlah' => 4,
                'min_stok' => 5,
                'last_transaction_at' => now(),
            ]);
        }

        $summary = $this->service->summary($this->expressBranch->cabang_id);

        $this->assertSame(1, $summary['total_items']);
        $this->assertSame(12, $summary['total_stock']);
    }

    public function test_new_express_transaction_uses_canonical_express_branch_identity(): void
    {
        $item = $this->makeItem();

        $transaction = $this->record(InventoryTransaction::TYPE_IN, 5, $item, $this->expressSewa);

        $this->assertSame($this->expressBranch->cabang_id, $transaction->cabang_id);
        $this->assertSame('Express', $transaction->inventory_branch_label);
        $this->assertSame(5, $this->stockOf($item, $this->expressBranch));
        $this->assertSame(0, $this->stockOf($item, $this->expressSewa));
    }

    public function test_legacy_express_transactions_remain_traceable_under_express(): void
    {
        $item = $this->makeItem();

        $legacy = InventoryTransaction::create([
            'nomor_transaksi' => 'INV-LEGACY-0001',
            'tanggal_transaksi' => now()->toDateString(),
            'jenis_transaksi' => InventoryTransaction::TYPE_IN,
            'inventory_item_id' => $item->id,
            'cabang_id' => $this->expressSewa->cabang_id,
            'jumlah' => 3,
            'stok_sebelum' => 0,
            'stok_sesudah' => 3,
            'alasan' => 'Data historis Express Sewa',
        ]);

        $this->assertSame('Express', $legacy->inventory_branch_label);
        $this->assertSame('Express Sewa', $legacy->source_branch_label);

        $this->assertContains(
            $this->expressSewa->cabang_id,
            InventoryBranches::expandCabangIds($this->expressBranch->cabang_id),
        );
        $this->assertContains(
            $this->expressSelfRun->cabang_id,
            InventoryBranches::expandCabangIds($this->expressBranch->cabang_id),
        );

        Livewire::test(ListInventoryTransactions::class)
            ->filterTable('cabang_id', $this->expressBranch->cabang_id)
            ->assertCanSeeTableRecords([$legacy]);
    }

    public function test_non_inventory_branch_is_rejected_for_stock_transactions(): void
    {
        $item = $this->makeItem();
        $wedding = $this->makeBranch('C0006', 'Wedding Photomate');

        try {
            $this->record(InventoryTransaction::TYPE_IN, 1, $item, $wedding);
            $this->fail('Cabang non-Inventory seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cabang_id', $exception->errors());
        }
    }

    public function test_inventory_pages_do_not_expose_legacy_express_branch_labels(): void
    {
        $this->makeItem();

        $this->get('/admin/inventory')
            ->assertOk()
            ->assertDontSee('Express Sewa')
            ->assertDontSee('Express Self Run');

        $this->get('/admin/inventory-stok')
            ->assertOk()
            ->assertSee('Express')
            ->assertDontSee('Express Sewa')
            ->assertDontSee('Express Self Run');

        $this->get('/admin/inventory-barang')
            ->assertOk()
            ->assertSee('Express')
            ->assertDontSee('Express Sewa')
            ->assertDontSee('Express Self Run');

        $this->get('/admin/inventory-riwayat')
            ->assertOk()
            ->assertDontSee('Express Sewa');

        $this->get('/admin/inventory-riwayat/create')
            ->assertOk()
            ->assertDontSee('Express Sewa');
    }

    public function test_non_admin_roles_are_forbidden_from_pages_and_backend_mutations(): void
    {
        $item = $this->makeItem();

        foreach ([$this->crew, $this->supervisor] as $user) {
            Auth::logout();
            $this->flushSession();
            $this->actingAs($user);

            $this->assertFalse(InventoryAccess::allowed($user));
            $this->assertFalse(InventoryOverview::canAccess());
            $this->assertFalse(InventoryStocks::canAccess());
            $this->assertFalse(InventoryItemResource::canViewAny());
            $this->assertFalse(InventoryTransactionResource::canViewAny());

            $this->get('/admin/inventory')->assertForbidden();
            $this->get('/admin/inventory-barang')->assertForbidden();
            $this->get('/admin/inventory-stok')->assertForbidden();
            $this->get('/admin/inventory-riwayat')->assertForbidden();

            try {
                $this->service->record([
                    'jenis_transaksi' => InventoryTransaction::TYPE_IN,
                    'tanggal_transaksi' => now()->toDateString(),
                    'inventory_item_id' => $item->id,
                    'cabang_id' => $this->branchA->cabang_id,
                    'jumlah' => 1,
                ]);
                $this->fail('Role non-admin seharusnya tidak dapat mencatat transaksi stok.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_admin_and_super_admin_roles_can_access_inventory(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(InventoryAccess::allowed($this->admin));
        $this->assertTrue(InventoryOverview::canAccess());
        $this->assertTrue(InventoryStocks::canAccess());

        $superAdmin = $this->makeUser('SUP01', 'Super Admin Inventory', 'R09', 'super.admin@photomate.id', '9000000000000004', 'Super Admin');

        Auth::logout();
        $this->flushSession();
        $this->actingAs($superAdmin);
        $this->assertTrue(InventoryAccess::allowed($superAdmin));
        $this->assertTrue(InventoryOverview::canAccess());
        $this->assertTrue(InventoryTransactionResource::canViewAny());
    }

    public function test_livewire_transaction_page_persists_transaction_and_updates_stock(): void
    {
        $item = $this->makeItem();

        Livewire::test(CreateInventoryTransaction::class)
            ->fillForm([
                'jenis_transaksi' => InventoryTransaction::TYPE_IN,
                'tanggal_transaksi' => now()->toDateString(),
                'inventory_item_id' => $item->id,
                'cabang_id' => $this->branchA->cabang_id,
                'jumlah' => 8,
                'alasan' => 'Penerimaan perlengkapan',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(8, $this->stockOf($item, $this->branchA));
        $this->assertSame(1, InventoryTransaction::query()->count());
    }

    public function test_inventory_overview_empty_state_renders(): void
    {
        $this->get('/admin/inventory')
            ->assertOk()
            ->assertSee('Belum ada barang');
    }

    public function test_inventory_pages_render_for_admin(): void
    {
        $item = $this->makeItem();
        $this->record(InventoryTransaction::TYPE_IN, 4, $item, $this->branchA, null, 'Stok awal');

        $this->get('/admin/inventory')->assertOk();
        $this->get('/admin/inventory-barang')->assertOk();
        $this->get('/admin/inventory-barang/'.$item->id)->assertOk();
        $this->get('/admin/inventory-barang/'.$item->id.'/edit')->assertOk();
        $this->get('/admin/inventory-stok')->assertOk();
        $this->get('/admin/inventory-riwayat')->assertOk();
        $this->get('/admin/inventory-riwayat/create')->assertOk();
    }

    private function makeUser(
        string $id,
        string $name,
        string $roleId,
        string $email,
        string $nik,
        string $roleName,
    ): Karyawan {
        $user = Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => 'P01',
            'nik' => $nik,
            'nama_lengkap' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($roleName);

        return $user;
    }

    private function makeBranch(string $id, string $name): Cabang
    {
        return Cabang::create([
            'cabang_id' => $id,
            'perusahaan_id' => 'P01',
            'nama_cabang' => $name,
            'alamat' => $name.' Outlet',
        ]);
    }

    private function makeItem(
        string $sku = 'KRT-2R',
        string $name = 'Kertas Foto 2R',
        int $minStock = 5,
        string $unit = 'rim',
    ): InventoryItem {
        $item = InventoryItem::create([
            'kode_barang' => $sku,
            'nama_barang' => $name,
            'kategori' => 'Kertas Foto',
            'satuan' => $unit,
            'min_stok_default' => $minStock,
            'is_active' => true,
        ]);

        $this->service->ensureStocks($item);

        return $item;
    }

    private function record(
        string $type,
        ?int $quantity,
        InventoryItem $item,
        Cabang $branch,
        ?int $actualStock = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): InventoryTransaction {
        return $this->service->record([
            'jenis_transaksi' => $type,
            'tanggal_transaksi' => now()->toDateString(),
            'inventory_item_id' => $item->id,
            'cabang_id' => $branch->cabang_id,
            'jumlah' => $quantity,
            'stok_aktual' => $actualStock,
            'alasan' => $reason,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function stockOf(InventoryItem $item, Cabang $branch): int
    {
        return (int) InventoryStock::query()
            ->where('inventory_item_id', $item->id)
            ->where('cabang_id', $branch->cabang_id)
            ->value('jumlah');
    }
}
