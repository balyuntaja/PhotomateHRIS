<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Perusahaan;
use App\Services\InvoiceFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeuanganBackfillInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected int $invoiceSequence = 900;

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

    public function test_backfill_mencatat_invoice_lunas_lama_dan_idempotent(): void
    {
        $lunas = $this->makeLegacyInvoice(['status' => 'Lunas']);
        $this->makeLegacyInvoice(['status' => 'Belum Dibayar']);

        $this->assertDatabaseCount('financial_transactions', 0);

        $this->artisan('keuangan:backfill-invoice')->assertSuccessful();

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', [
            'source_type' => InvoiceFinancialService::SOURCE,
            'source_id' => (string) $lunas->id,
            'amount' => 2599000,
        ]);

        $transaction = FinancialTransaction::where('source_id', (string) $lunas->id)->firstOrFail();
        $this->assertSame('2026-10-12', $transaction->transaction_date->toDateString());
        $this->assertSame(InvoiceFinancialService::CATEGORY_NAME, $transaction->category?->name);

        $this->artisan('keuangan:backfill-invoice')->assertSuccessful();

        $this->assertSame(1, FinancialTransaction::withTrashed()->count());
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_dry_run_tidak_menyimpan_dan_invoice_tanpa_tanggal_acara_dilewati(): void
    {
        $this->makeLegacyInvoice(['status' => 'Lunas']);
        $this->makeLegacyInvoice(['status' => 'Lunas', 'event_date' => null, 'event_end_date' => null]);

        $this->artisan('keuangan:backfill-invoice', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('financial_transactions', 0);

        $this->artisan('keuangan:backfill-invoice')->assertSuccessful();

        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_backfill_gagal_bila_aktor_tidak_ditemukan(): void
    {
        $this->makeLegacyInvoice(['status' => 'Lunas']);

        $this->artisan('keuangan:backfill-invoice', ['--actor' => 'K9999'])->assertFailed();

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    /**
     * Simulasi data lama: invoice sudah berstatus Lunas sebelum integrasi
     * keuangan aktif, sehingga event observer sengaja dilewati.
     */
    protected function makeLegacyInvoice(array $attributes = []): Invoice
    {
        return Invoice::withoutEvents(fn (): Invoice => Invoice::create(array_merge([
            'invoice_number' => 'INV-PMT-2026-' . (++$this->invoiceSequence),
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
            'status' => 'Lunas',
            'notes' => null,
        ], $attributes)));
    }
}
