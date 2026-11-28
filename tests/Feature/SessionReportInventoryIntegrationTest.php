<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryTransactionResource\Pages\ListInventoryTransactions;
use App\Models\Cabang;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\InventoryService;
use App\Services\SessionReportInventoryService;
use App\Services\SessionWorkflowService;
use App\Utils\InventoryAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SessionReportInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;

    protected Cabang $expressCanonical;

    protected Cabang $expressSharingProfit;

    protected Cabang $expressSelfRun;

    protected Cabang $expressSewa;

    protected Cabang $janus;

    protected Cabang $golio;

    protected Karyawan $superAdmin;

    protected Karyawan $admin;

    protected Karyawan $supervisor;

    protected Karyawan $crew;

    protected InventoryItem $photoPaper;

    protected InventoryService $inventoryService;

    protected SessionWorkflowService $workflowService;

    protected SessionReportInventoryService $reportInventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        PricingSetting::firstOrCreate(
            ['is_active' => true],
            [
                'name' => 'Standard Pricing',
                'base_price' => 30000,
                'additional_session_price' => 15000,
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ],
        );

        $this->perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->expressCanonical = $this->makeBranch('C0003', 'Photomate Express');
        $this->expressSharingProfit = $this->makeBranch('C0008', 'Express Sharing Profit');
        $this->expressSelfRun = $this->makeBranch('C0009', 'Express Self Run');
        $this->expressSewa = $this->makeBranch('C0005', 'Express Sewa');
        $this->janus = $this->makeBranch('C0004', 'Newspaper Janus');
        $this->golio = $this->makeBranch('C0006', 'Golio');

        $this->superAdmin = $this->makeUser('SA001', 'Super Admin Photomate', 'R09', 'Super Admin', 'sa@photomate.id', '9100000000000001');
        $this->admin = $this->makeUser('AD001', 'Admin Inventory', 'R01', 'Admin', 'admin@photomate.id', '9100000000000002');
        $this->supervisor = $this->makeUser('SP001', 'Supervisor Operasional', 'R08', 'Supervisor', 'spv@photomate.id', '9100000000000003');
        $this->crew = $this->makeUser('CR001', 'Crew Booth', 'R07', 'Karyawan', 'crew@photomate.id', '9100000000000004');

        $this->inventoryService = app(InventoryService::class);
        $this->workflowService = app(SessionWorkflowService::class);
        $this->reportInventoryService = app(SessionReportInventoryService::class);

        $this->actingAs($this->admin);

        $this->photoPaper = InventoryItem::create([
            'kode_barang' => 'KRT-FOTO-01',
            'nama_barang' => 'Kertas Foto',
            'kategori' => 'Kertas Foto',
            'satuan' => 'lembar',
            'min_stok_default' => 50,
            'is_active' => true,
        ]);

        // Stok awal per 7 November 2026
        $this->inventoryService->registerBranchStock($this->photoPaper, $this->expressCanonical->cabang_id, 500, 50);
        $this->inventoryService->registerBranchStock($this->photoPaper, $this->janus->cabang_id, 300, 50);
        $this->inventoryService->registerBranchStock($this->photoPaper, $this->golio->cabang_id, 200, 50);

        // Hapus transaksi stok awal setup bila registerBranchStock membuat record stok awal (biarkan stok murni 500/300/200)
        InventoryTransaction::query()->delete();
    }

    public function test_express_on_6_november_and_earlier_does_not_deduct_stock_even_when_approved_after_7_november(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAs($this->supervisor);

        $sharingBeforeCutoff = $this->makeTransactionReport($this->expressSharingProfit, '2026-11-06', 20, 'SR-EXP-20261106-001');
        $selfRunBeforeCutoff = $this->makeManualReport($this->expressSelfRun, '2026-11-06', 15, 30, 'SR-SRN-20261106-002');
        $sewaOctober = $this->makeManualReport($this->expressSewa, '2026-10-25', 10, 25, 'SR-SEW-20261025-003');

        $this->workflowService->approve($sharingBeforeCutoff, $this->supervisor);
        $this->workflowService->approve($selfRunBeforeCutoff, $this->supervisor);
        $this->workflowService->approve($sewaOctober, $this->supervisor);

        $this->assertSame(500, $this->branchStock($this->expressCanonical));
        $this->assertSame(0, InventoryTransaction::query()->count());
    }

    public function test_express_on_7_november_inclusive_deducts_stock_and_records_complete_traceable_transaction(): void
    {
        $this->travelTo('2026-11-08 09:30:00');

        // Supervisor tidak punya akses admin Inventory manual, namun approval tetap wajib mencatat stok otomatis
        $this->assertFalse(InventoryAccess::allowed($this->supervisor));
        $this->actingAs($this->supervisor);

        $report = $this->makeTransactionReport($this->expressCanonical, '2026-11-07', 20, 'SR-20261107-001');
        $this->workflowService->approve($report, $this->supervisor, 'Approved tepat cutoff');

        $this->assertSame(480, $this->branchStock($this->expressCanonical));
        $this->assertSame(300, $this->branchStock($this->janus));
        $this->assertSame(200, $this->branchStock($this->golio));

        $tx = InventoryTransaction::query()->sole();
        $this->assertSame(InventoryTransaction::TYPE_OUT, $tx->jenis_transaksi);
        $this->assertSame($this->photoPaper->id, $tx->inventory_item_id);
        $this->assertSame($this->expressCanonical->cabang_id, $tx->cabang_id);
        $this->assertSame('Express', $tx->inventory_branch_label);
        $this->assertSame(20, $tx->jumlah);
        $this->assertSame(20, $tx->jumlah_keluar);
        $this->assertSame(500, $tx->stok_sebelum);
        $this->assertSame(480, $tx->stok_sesudah);
        $this->assertSame('2026-11-07', $tx->tanggal_transaksi->toDateString());
        $this->assertSame($report->id, $tx->session_report_id);
        $this->assertSame(InventoryTransaction::REFERENCE_SESSION_REPORT, $tx->reference_type);
        $this->assertSame('SR-20261107-001', $tx->reference_number);
        $this->assertStringContainsString('Penggunaan kertas foto otomatis dari rekap sesi', (string) $tx->alasan);
        $this->assertStringContainsString('SR-20261107-001', (string) $tx->alasan);
        $this->assertSame($this->supervisor->karyawan_id, $tx->user_id);
        $this->assertSame($this->supervisor->nama_lengkap, $tx->user_name);
    }

    public function test_express_self_run_sewa_and_sharing_profit_all_deduct_from_express_stock(): void
    {
        $this->actingAs($this->supervisor);

        // 1. Express Self Run: 25 lembar
        $selfRun = $this->makeManualReport($this->expressSelfRun, '2026-11-07', 12, 25, 'SR-SELFRUN-001');
        $this->workflowService->approve($selfRun, $this->supervisor);
        $this->assertSame(475, $this->branchStock($this->expressCanonical));

        // 2. Express Sewa: 40 lembar
        $sewa = $this->makeManualReport($this->expressSewa, '2026-11-09', 20, 40, 'SR-SEWA-002');
        $this->workflowService->approve($sewa, $this->supervisor);
        $this->assertSame(435, $this->branchStock($this->expressCanonical));

        // 3. Express Sharing Profit: 15 lembar
        $sharingProfit = $this->makeTransactionReport($this->expressSharingProfit, '2026-11-12', 15, 'SR-SHARING-003');
        $this->workflowService->approve($sharingProfit, $this->supervisor);
        $this->assertSame(420, $this->branchStock($this->expressCanonical));

        $this->assertSame(3, InventoryTransaction::query()->count());
        $this->assertSame(
            ['SR-SELFRUN-001', 'SR-SEWA-002', 'SR-SHARING-003'],
            InventoryTransaction::query()->orderBy('id')->pluck('reference_number')->all(),
        );

        // Cabang lain tidak berubah
        $this->assertSame(300, $this->branchStock($this->janus));
        $this->assertSame(200, $this->branchStock($this->golio));
    }

    public function test_newspaper_janus_respects_7_november_cutoff_and_deducts_janus_stock(): void
    {
        $this->travelTo('2026-11-10 14:00:00');
        $this->actingAs($this->supervisor);

        // Sebelum 7 November -> TIDAK mengurangi stok walaupun di-approve tanggal 10 November
        $janusBefore = $this->makeTransactionReport($this->janus, '2026-11-06', 30, 'SR-JNS-20261106-001');
        $this->workflowService->approve($janusBefore, $this->supervisor);
        $this->assertSame(300, $this->branchStock($this->janus));
        $this->assertSame(0, InventoryTransaction::query()->count());

        // Tepat 7 November -> mengurangi stok sebesar 35 lembar
        $janusOnCutoff = $this->makeTransactionReport($this->janus, '2026-11-07', 35, 'SR-JNS-20261107-002');
        $this->workflowService->approve($janusOnCutoff, $this->supervisor);
        $this->assertSame(265, $this->branchStock($this->janus));

        // Setelah 7 November -> tetap mengurangi stok sebesar 15 lembar
        $janusAfter = $this->makeTransactionReport($this->janus, '2026-11-15', 15, 'SR-JNS-20261115-003');
        $this->workflowService->approve($janusAfter, $this->supervisor);
        $this->assertSame(250, $this->branchStock($this->janus));

        $this->assertSame(2, InventoryTransaction::query()->count());

        $firstJanusTx = InventoryTransaction::query()->orderBy('id')->firstOrFail();
        $this->assertSame($this->janus->cabang_id, $firstJanusTx->cabang_id);
        $this->assertSame('Newspaper Janus', $firstJanusTx->inventory_branch_label);
        $this->assertSame(300, $firstJanusTx->stok_sebelum);
        $this->assertSame(265, $firstJanusTx->stok_sesudah);
        $this->assertSame('SR-JNS-20261107-002', $firstJanusTx->reference_number);

        // Express & Golio tetap utuh
        $this->assertSame(500, $this->branchStock($this->expressCanonical));
        $this->assertSame(200, $this->branchStock($this->golio));
    }

    public function test_golio_after_7_november_never_deducts_automatically_and_manual_transactions_remain_normal(): void
    {
        $this->actingAs($this->supervisor);

        $golioOnCutoff = $this->makeTransactionReport($this->golio, '2026-11-07', 40, 'SR-GOL-20261107-001');
        $golioAfterCutoff = $this->makeTransactionReport($this->golio, '2026-11-18', 25, 'SR-GOL-20261118-002');

        $this->workflowService->approve($golioOnCutoff, $this->supervisor);
        $this->workflowService->approve($golioAfterCutoff, $this->supervisor);

        // Sinkronisasi paksa pun tidak boleh memotong stok Golio
        $this->assertNull($this->reportInventoryService->syncForReport($golioOnCutoff, $this->supervisor));
        $this->reportInventoryService->syncEligibleReports($this->supervisor);

        $this->assertSame(200, $this->branchStock($this->golio));
        $this->assertSame(0, InventoryTransaction::query()->count());

        // Pencatatan manual Barang Masuk & Barang Keluar untuk Golio oleh Admin tetap berfungsi normal
        $this->actingAs($this->admin);

        $manualIn = $this->inventoryService->record([
            'jenis_transaksi' => InventoryTransaction::TYPE_IN,
            'tanggal_transaksi' => '2026-11-18',
            'inventory_item_id' => $this->photoPaper->id,
            'cabang_id' => $this->golio->cabang_id,
            'jumlah' => 50,
            'alasan' => 'Pembelian kertas foto Golio manual',
        ]);

        $manualOut = $this->inventoryService->record([
            'jenis_transaksi' => InventoryTransaction::TYPE_OUT,
            'tanggal_transaksi' => '2026-11-18',
            'inventory_item_id' => $this->photoPaper->id,
            'cabang_id' => $this->golio->cabang_id,
            'jumlah' => 30,
            'alasan' => 'Pemakaian kertas foto Golio manual',
        ]);

        $this->assertSame(220, $this->branchStock($this->golio));
        $this->assertNull($manualIn->session_report_id);
        $this->assertNull($manualOut->session_report_id);
    }

    public function test_reapproval_and_repeated_sync_do_not_create_duplicate_stock_deductions(): void
    {
        $this->actingAs($this->supervisor);

        $report = $this->makeManualReport($this->expressSelfRun, '2026-11-08', 10, 20, 'SR-DUP-001');
        $this->workflowService->approve($report, $this->supervisor);

        $this->assertSame(480, $this->branchStock($this->expressCanonical));
        $this->assertSame(1, InventoryTransaction::query()->count());

        // Percobaan approval ulang harus tertolak dan tidak menambah transaksi stok
        try {
            $this->workflowService->approve($report->fresh(), $this->supervisor);
            $this->fail('Approval ulang pada rekap Approved seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('disetujui', $e->getMessage());
        }

        // Pemanggilan syncForReport & syncEligibleReports berulang kali harus 100% idempoten
        $this->assertNull($this->reportInventoryService->syncForReport($report->fresh(), $this->supervisor));
        $this->assertNull($this->reportInventoryService->syncForReport($report->fresh(), $this->admin));

        $batchResult = $this->reportInventoryService->syncEligibleReports($this->admin);
        $this->assertSame(1, $batchResult['processed']);
        $this->assertSame(0, $batchResult['created']);

        $this->assertSame(480, $this->branchStock($this->expressCanonical));
        $this->assertSame(1, InventoryTransaction::query()->count());
    }

    public function test_sheet_count_correction_adjusts_stock_strictly_by_delta_without_deleting_history(): void
    {
        $this->actingAs($this->supervisor);

        $report = $this->makeManualReport($this->expressSelfRun, '2026-11-11', 10, 20, 'SR-DELTA-001');
        $this->workflowService->approve($report, $this->supervisor);

        // Awal: 500 - 20 = 480
        $this->assertSame(480, $this->branchStock($this->expressCanonical));

        // Koreksi naik: 20 -> 32 lembar (selisih +12 lembar keluar, bukan -32 penuh!)
        $report = $this->workflowService->correctApprovedSheets(
            $report,
            $this->superAdmin,
            newTotalSheets: 32,
            newTotalSessions: 16,
            reason: 'Penyesuaian bukti cetak booth',
        );

        $this->assertSame(468, $this->branchStock($this->expressCanonical));
        $this->assertSame(32, $this->reportInventoryService->getNetDeductedSheets($report));
        $this->assertSame(2, InventoryTransaction::query()->where('session_report_id', $report->id)->count());

        $deltaOutTx = InventoryTransaction::query()->where('session_report_id', $report->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(InventoryTransaction::TYPE_OUT, $deltaOutTx->jenis_transaksi);
        $this->assertSame(12, $deltaOutTx->jumlah);
        $this->assertSame(480, $deltaOutTx->stok_sebelum);
        $this->assertSame(468, $deltaOutTx->stok_sesudah);
        $this->assertStringContainsString('koreksi rekap sesi', (string) $deltaOutTx->alasan);

        // Koreksi turun: 32 -> 25 lembar (selisih 7 lembar dikembalikan via Barang Masuk)
        $report = $this->workflowService->correctApprovedSheets(
            $report,
            $this->superAdmin,
            newTotalSheets: 25,
            newTotalSessions: 14,
            reason: 'Koreksi cetakan gagal uji coba',
        );

        $this->assertSame(475, $this->branchStock($this->expressCanonical));
        $this->assertSame(25, $this->reportInventoryService->getNetDeductedSheets($report));
        $this->assertSame(3, InventoryTransaction::query()->where('session_report_id', $report->id)->count());

        $deltaInTx = InventoryTransaction::query()->where('session_report_id', $report->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(InventoryTransaction::TYPE_IN, $deltaInTx->jenis_transaksi);
        $this->assertSame(7, $deltaInTx->jumlah);
        $this->assertSame(7, $deltaInTx->jumlah_masuk);
        $this->assertSame(468, $deltaInTx->stok_sebelum);
        $this->assertSame(475, $deltaInTx->stok_sesudah);
        $this->assertStringContainsString('Pengembalian selisih kertas foto otomatis', (string) $deltaInTx->alasan);

        // Koreksi dengan nilai sama (25 -> 25) tidak membuat transaksi tambahan
        $this->workflowService->correctApprovedSheets($report, $this->superAdmin, 25, 14, 'Tidak berubah');
        $this->assertSame(3, InventoryTransaction::query()->where('session_report_id', $report->id)->count());
        $this->assertSame(475, $this->branchStock($this->expressCanonical));
    }

    public function test_cancellation_and_deletion_restore_stock_via_reversal_and_preserve_audit_history(): void
    {
        $this->actingAs($this->supervisor);

        // Skenario A: Pembatalan approval (status berubah dari APPROVED menjadi REJECTED)
        $expressReport = $this->makeManualReport($this->expressSewa, '2026-11-12', 15, 30, 'SR-REV-EXP-001');
        $this->workflowService->approve($expressReport, $this->supervisor);
        $this->assertSame(470, $this->branchStock($this->expressCanonical));

        $cancelled = $this->workflowService->cancelApproval(
            $expressReport,
            $this->superAdmin,
            'Event salah input cabang dan dibatalkan',
            SessionReport::STATUS_REJECTED,
        );

        $this->assertSame(SessionReport::STATUS_REJECTED, $cancelled->status);
        $this->assertSame(500, $this->branchStock($this->expressCanonical));
        $this->assertSame(0, $this->reportInventoryService->getNetDeductedSheets($cancelled));

        // Riwayat transaksi asli & reversal keduanya tetap utuh (tidak ada yang dihapus)
        $expressTxs = InventoryTransaction::query()
            ->where('reference_number', 'SR-REV-EXP-001')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $expressTxs);
        $this->assertSame(InventoryTransaction::TYPE_OUT, $expressTxs[0]->jenis_transaksi);
        $this->assertSame(30, $expressTxs[0]->jumlah);
        $this->assertSame(InventoryTransaction::TYPE_IN, $expressTxs[1]->jenis_transaksi);
        $this->assertSame(30, $expressTxs[1]->jumlah);
        $this->assertSame(470, $expressTxs[1]->stok_sebelum);
        $this->assertSame(500, $expressTxs[1]->stok_sesudah);
        $this->assertStringContainsString('Pembalikan stok kertas foto otomatis', (string) $expressTxs[1]->alasan);

        // Pemanggilan reverse ulang tidak menggandakan pengembalian stok
        $this->assertNull($this->reportInventoryService->reverseForReport($cancelled, $this->superAdmin, 'Ulang'));
        $this->assertSame(500, $this->branchStock($this->expressCanonical));

        // Skenario B: Penghapusan rekap Approved oleh Super Admin mengembalikan stok via reversal & menjaga histori
        $janusReport = $this->makeTransactionReport($this->janus, '2026-11-14', 25, 'SR-REV-JNS-002');
        $this->workflowService->approve($janusReport, $this->supervisor);
        $this->assertSame(275, $this->branchStock($this->janus));

        $this->actingAs($this->superAdmin);
        $janusReport->delete();

        $this->assertSame(300, $this->branchStock($this->janus));

        $janusTxs = InventoryTransaction::query()
            ->where('reference_number', 'SR-REV-JNS-002')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $janusTxs);
        $this->assertNull($janusTxs[0]->session_report_id);
        $this->assertNull($janusTxs[1]->session_report_id);
        $this->assertSame('SR-REV-JNS-002', $janusTxs[0]->reference_number);
        $this->assertSame('SR-REV-JNS-002', $janusTxs[1]->reference_number);
        $this->assertSame(InventoryTransaction::TYPE_OUT, $janusTxs[0]->jenis_transaksi);
        $this->assertSame(InventoryTransaction::TYPE_IN, $janusTxs[1]->jenis_transaksi);
    }

    public function test_riwayat_stok_page_displays_and_searches_session_report_reference(): void
    {
        $this->actingAs($this->supervisor);

        $expressReport = $this->makeTransactionReport($this->expressSharingProfit, '2026-11-07', 20, 'SR-UI-EXP-007');
        $janusReport = $this->makeTransactionReport($this->janus, '2026-11-08', 35, 'SR-UI-JNS-008');

        $this->workflowService->approve($expressReport, $this->supervisor);
        $this->workflowService->approve($janusReport, $this->supervisor);

        $expressTx = InventoryTransaction::query()->where('reference_number', 'SR-UI-EXP-007')->firstOrFail();
        $janusTx = InventoryTransaction::query()->where('reference_number', 'SR-UI-JNS-008')->firstOrFail();

        $this->actingAs($this->admin);

        Livewire::test(ListInventoryTransactions::class)
            ->assertCanSeeTableRecords([$expressTx, $janusTx])
            ->assertSee('SR-UI-EXP-007')
            ->assertSee('SR-UI-JNS-008')
            ->assertSee('Kertas Foto')
            ->assertSee('Barang Keluar')
            ->assertSee($this->supervisor->nama_lengkap)
            ->searchTable('SR-UI-EXP-007')
            ->assertCanSeeTableRecords([$expressTx])
            ->assertCanNotSeeTableRecords([$janusTx])
            ->mountTableAction('view', $expressTx)
            ->assertSee('Referensi Rekap Sesi')
            ->assertSee('SR-UI-EXP-007')
            ->assertSee('Penggunaan kertas foto otomatis dari rekap sesi');
    }

    private function makeBranch(string $id, string $name): Cabang
    {
        return Cabang::create([
            'cabang_id' => $id,
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => $name,
            'alamat' => $name . ' Outlet',
        ]);
    }

    private function makeUser(
        string $id,
        string $name,
        string $roleId,
        string $roleName,
        string $email,
        string $nik,
    ): Karyawan {
        Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'web'],
            ['role_id' => $roleId],
        );

        $user = Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nik' => $nik,
            'nama_lengkap' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1995-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($roleName);

        return $user;
    }

    private function makeTransactionReport(
        Cabang $branch,
        string $reportDate,
        int $totalSheets,
        string $reportNumber,
    ): SessionReport {
        $report = SessionReport::create([
            'report_number' => $reportNumber,
            'report_date' => $reportDate,
            'cabang_id' => $branch->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crew->karyawan_id,
            'submitted_at' => now(),
        ]);

        $report->crews()->attach([$this->crew->karyawan_id]);

        // Setiap transaksi Rp 30.000 menghasilkan 1 sesi/lembar pada PricingService
        for ($i = 0; $i < $totalSheets; $i++) {
            $report->transactions()->create([
                'session_count' => 1,
                'payment_method' => $i % 2 === 0 ? 'QRIS' : 'CASH',
                'amount' => 30000,
            ]);
        }

        return $report;
    }

    private function makeManualReport(
        Cabang $branch,
        string $reportDate,
        int $totalSessions,
        int $totalSheets,
        string $reportNumber,
    ): SessionReport {
        $report = SessionReport::create([
            'report_number' => $reportNumber,
            'report_date' => $reportDate,
            'cabang_id' => $branch->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'wedding_total_sessions' => $totalSessions,
            'wedding_total_sheets' => $totalSheets,
            'total_transactions' => $totalSessions,
            'total_sessions' => $totalSheets,
            'submitted_by' => $this->crew->karyawan_id,
            'submitted_at' => now(),
        ]);

        $report->crews()->attach([$this->crew->karyawan_id]);

        return $report;
    }

    private function branchStock(Cabang $branch): int
    {
        return (int) InventoryStock::query()
            ->where('inventory_item_id', $this->photoPaper->id)
            ->where('cabang_id', $branch->cabang_id)
            ->value('jumlah');
    }
}
