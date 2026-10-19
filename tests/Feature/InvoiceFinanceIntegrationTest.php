<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\InvoiceFinancialService;
use App\Services\KeuanganService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceFinanceIntegrationTest extends TestCase
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

    public function test_invoice_belum_dibayar_dan_sebagian_dibayar_tidak_menambah_pemasukan(): void
    {
        $invoice = $this->makeInvoice();

        $this->assertDatabaseCount('financial_transactions', 0);

        $invoice->update([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 1000000,
            'dp_date' => '2026-10-10',
        ]);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_invoice_lunas_mencatat_satu_pemasukan_sesuai_mapping(): void
    {
        $invoice = $this->makeInvoice();

        $invoice->update(['status' => 'Lunas']);

        $this->assertDatabaseCount('financial_transactions', 1);

        $transaction = $this->invoiceTransaction($invoice);

        $this->assertSame(FinancialTransaction::TYPE_INCOME, $transaction->transaction_type);
        $this->assertSame(2599000, $transaction->amount);
        $this->assertSame($this->pelunasanInvoiceCategory()->id, $transaction->category_id);
        $this->assertSame('Photomate Express', $transaction->account?->name);
        $this->assertSame(
            FinancialAccount::where('name', InvoiceFinancialService::ACCOUNT_NAME)->value('id'),
            $transaction->account_id
        );
        $this->assertSame('2026-10-12', $transaction->transaction_date->toDateString());
        $this->assertSame(10, $transaction->period_month);
        $this->assertSame(2026, $transaction->period_year);
        $this->assertSame(FinancialTransaction::PAYMENT_STATUS_LUNAS, $transaction->payment_status);
        $this->assertSame(InvoiceFinancialService::SOURCE, $transaction->source_type);
        $this->assertSame((string) $invoice->id, $transaction->source_id);
        $this->assertSame(
            'Pelunasan Invoice ' . $invoice->invoice_number . ' - Keluarga Uji',
            $transaction->description
        );
        $this->assertNull($transaction->submitted_by);
        $this->assertSame('-', $transaction->submitted_by_label);
    }

    public function test_invoice_lunas_mencatat_submitter_dan_tidak_menimpanya_saat_disimpan_user_lain(): void
    {
        $pertama = $this->makeSuperAdmin();

        $this->actingAs($pertama);

        $invoice = $this->makeInvoice(['status' => 'Lunas']);
        $transaction = $this->invoiceTransaction($invoice);

        $this->assertSame($pertama->karyawan_id, $transaction->submitted_by);
        $this->assertSame($pertama->nama_lengkap, $transaction->submitted_by_name);

        $kedua = Karyawan::create([
            'karyawan_id' => 'K0002',
            'role_id' => 'R01',
            'perusahaan_id' => 'P0001',
            'nik' => '888888888888',
            'nama_lengkap' => 'Admin Kedua Uji',
            'email' => 'admin.kedua@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $this->actingAs($kedua);

        $invoice->update(['notes' => 'Disimpan oleh user lain']);

        $transaction->refresh();

        $this->assertSame($pertama->karyawan_id, $transaction->submitted_by);
        $this->assertSame($pertama->nama_lengkap, $transaction->submitted_by_name);
    }

    public function test_acara_multi_hari_dicatat_sekali_pada_tanggal_mulai(): void
    {
        $invoice = $this->makeInvoice([
            'event_date' => '2026-10-12',
            'event_end_date' => '2026-10-13',
            'total' => 3000000,
        ]);

        $invoice->update(['status' => 'Lunas']);

        $this->assertDatabaseCount('financial_transactions', 1);

        $transaction = $this->invoiceTransaction($invoice);

        $this->assertSame('2026-10-12', $transaction->transaction_date->toDateString());
        $this->assertSame(3000000, $transaction->amount);
    }

    public function test_menyimpan_ulang_invoice_lunas_tidak_membuat_transaksi_ganda(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Lunas']);

        $invoice->update(['status' => 'Lunas']);
        $invoice->update(['notes' => 'Catatan tambahan']);
        app(InvoiceFinancialService::class)->sync($invoice->fresh());

        $this->assertSame(1, FinancialTransaction::withTrashed()->count());
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_status_tidak_lunas_membatalkan_pencatatan_dan_pulih_saat_lunas_kembali(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Lunas']);

        $invoice->update(['status' => 'Sebagian Dibayar']);

        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSoftDeleted('financial_transactions', [
            'source_type' => InvoiceFinancialService::SOURCE,
            'source_id' => (string) $invoice->id,
        ]);

        $invoice->update(['status' => 'Lunas']);

        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(1, FinancialTransaction::withTrashed()->count());
        $this->assertNull($this->invoiceTransaction($invoice)->deleted_at);
    }

    public function test_perubahan_nominal_dan_tanggal_acara_memperbarui_transaksi_tanpa_duplikasi(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Lunas']);

        $invoice->update([
            'total' => 3000000,
            'event_date' => '2026-09-12',
            'event_end_date' => '2026-09-12',
        ]);

        $transaction = $this->invoiceTransaction($invoice)->refresh();

        $this->assertSame(3000000, $transaction->amount);
        $this->assertSame('2026-09-12', $transaction->transaction_date->toDateString());
        $this->assertSame(9, $transaction->period_month);
        $this->assertSame(1, FinancialTransaction::withTrashed()->count());
    }

    public function test_laporan_keuangan_mengikuti_periode_tanggal_acara(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-10-05',
            'dp_date' => '2026-10-10',
        ]);

        $invoice->update(['status' => 'Lunas']);

        $service = app(KeuanganService::class);
        $october = $service->resolvePeriod(KeuanganService::PRESET_CUSTOM, '2026-10-01', '2026-10-31');
        $september = $service->resolvePeriod(KeuanganService::PRESET_CUSTOM, '2026-09-01', '2026-09-30');

        $summary = $service->summary($october['start'], $october['end']);
        $this->assertSame(2599000, $summary['income']);
        $this->assertSame(2599000, $summary['balance']);
        $this->assertSame(0, $service->summary($september['start'], $september['end'])['income']);

        $transactionId = $this->invoiceTransaction($invoice)->id;

        $this->assertSame(
            [$transactionId],
            $service->transactionsForReport($october['start'], $october['end'])->pluck('id')->all()
        );
        $this->assertSame(
            [$transactionId],
            $service->transactionsForExport($october['start'], $october['end'])->pluck('id')->all()
        );
        $this->assertSame(0, $service->transactionsForReport($september['start'], $september['end'])->count());
    }

    public function test_cabang_invoice_diambil_dari_rekap_sesi_terkait(): void
    {
        $cabang = Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        $invoice = $this->makeInvoice();

        SessionReport::create([
            'report_date' => '2026-10-12',
            'cabang_id' => $cabang->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_DRAFT,
        ]);

        $invoice->update(['status' => 'Lunas']);

        $this->assertSame('C0005', $this->invoiceTransaction($invoice)->cabang_id);

        $tanpaRekap = $this->makeInvoice(['status' => 'Lunas']);
        $this->assertNull($this->invoiceTransaction($tanpaRekap)->cabang_id);
    }

    public function test_transaksi_manual_tidak_terpengaruh_sinkronisasi_invoice(): void
    {
        $manual = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 500000,
            'category_id' => $this->pelunasanInvoiceCategory()->id,
            'transaction_date' => '2026-10-12',
            'description' => 'Input manual',
        ]);

        $invoice = $this->makeInvoice(['status' => 'Lunas']);
        $invoice->update(['status' => 'Belum Dibayar']);
        $invoice->delete();

        $manual->refresh();

        $this->assertNull($manual->deleted_at);
        $this->assertNull($manual->source_type);
        $this->assertSame(500000, $manual->amount);
    }

    public function test_tabel_transaksi_keuangan_menampilkan_sumber_invoice_dan_tautan_detail(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Lunas']);

        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(ListFinancialTransactions::class)
            ->assertCanSeeTableRecords([$this->invoiceTransaction($invoice)])
            ->assertSee('/admin/invoices/' . $invoice->id . '/edit');
    }

    public function test_tabel_invoice_diurutkan_dari_invoice_terbaru(): void
    {
        // Sengaja dibuat agar urutan tanggal invoice berlawanan dengan urutan ID,
        // sehingga urutan berdasarkan ID (perilaku lama) tidak lolos pengujian ini.
        $terlama = $this->makeInvoice(['invoice_date' => '2026-08-01']);
        $tengah = $this->makeInvoice(['invoice_date' => '2026-09-15']);
        $terbaru = $this->makeInvoice(['invoice_date' => '2026-10-20']);

        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$terbaru, $tengah, $terlama], inOrder: true);
    }

    protected function makeSuperAdmin(): Karyawan
    {
        $role = Role::firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            ['role_id' => 'R01'],
        );

        $user = Karyawan::create([
            'karyawan_id' => 'K0001',
            'role_id' => $role->role_id,
            'perusahaan_id' => 'P0001',
            'nik' => '999999999999',
            'nama_lengkap' => 'Super Admin Uji',
            'email' => 'superadmin@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        return $user->refresh();
    }

    protected function makeInvoice(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_name' => 'Keluarga Uji',
            'event_name' => 'Wedding Uji',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Uji',
            'event_date' => '2026-10-12',
            'event_end_date' => '2026-10-12',
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-10-05',
            'subtotal' => 2599000,
            'discount' => 0,
            'total' => 2599000,
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
            'notes' => null,
        ], $attributes));
    }

    protected function invoiceTransaction(Invoice $invoice): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->where('source_type', InvoiceFinancialService::SOURCE)
            ->where('source_id', (string) $invoice->id)
            ->firstOrFail();
    }

    protected function pelunasanInvoiceCategory(): FinancialCategory
    {
        return FinancialCategory::query()
            ->where('name', InvoiceFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();
    }
}
