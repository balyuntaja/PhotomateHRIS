<?php

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\JournalPostingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class JournalPostingServiceTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_jurnal_seimbang_berhasil_dibuat_dan_diposting(): void
    {
        $entry = $this->postBalancedJournal();

        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertSame('100000.00', $entry->total_debit);
        $this->assertSame('100000.00', $entry->total_credit);
        $this->assertTrue($entry->is_balanced);
        $this->assertCount(2, $entry->lines);
        $this->assertStringStartsWith('JE-202610-', $entry->journal_number);
    }

    public function test_jurnal_tidak_seimbang_ditolak(): void
    {
        $this->expectException(DomainException::class);

        $this->postBalancedJournal(lines: [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 100000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 90000],
        ]);
    }

    public function test_jurnal_tanpa_baris_ditolak(): void
    {
        $this->expectException(DomainException::class);

        $this->postBalancedJournal(lines: []);
    }

    public function test_baris_dengan_debit_dan_kredit_sekaligus_ditolak(): void
    {
        $this->expectException(DomainException::class);

        $this->postBalancedJournal(lines: [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 100000, 'credit' => 100000],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 100000],
        ]);
    }

    public function test_akun_nonaktif_ditolak_saat_posting(): void
    {
        $draft = app(JournalPostingService::class)->createDraft(
            $this->journalData(),
            $this->balancedLines()
        );

        $this->accountingAccount('1001')->update(['is_active' => false]);

        $this->expectException(DomainException::class);

        app(JournalPostingService::class)->post($draft);
    }

    public function test_jurnal_tidak_dapat_diposting_pada_periode_closed(): void
    {
        FiscalPeriod::create([
            'year' => 2026,
            'month' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'status' => FiscalPeriod::STATUS_CLOSED,
        ]);

        $this->expectException(DomainException::class);

        $this->postBalancedJournal();
    }

    public function test_jurnal_posted_tidak_dapat_diedit(): void
    {
        $entry = $this->postBalancedJournal();

        $this->expectException(DomainException::class);

        $entry->update(['description' => 'Mencoba mengubah jurnal posted']);
    }

    public function test_reversal_menghasilkan_jurnal_berlawanan_tanpa_mengubah_jurnal_asli(): void
    {
        $entry = $this->postBalancedJournal();
        $reversal = app(JournalPostingService::class)->reverse(
            $entry,
            'Reversal uji',
            '2026-10-13'
        );

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame($entry->getKey(), $reversal->reversal_of_id);
        $this->assertSame('100000.00', $reversal->total_debit);
        $this->assertSame('100000.00', $reversal->total_credit);

        $assetLine = $reversal->lines->firstWhere(
            'chart_of_account_id',
            $this->accountingAccount('1001')->getKey()
        );
        $revenueLine = $reversal->lines->firstWhere(
            'chart_of_account_id',
            $this->accountingAccount('4000')->getKey()
        );

        $this->assertSame('100000.00', $assetLine->credit);
        $this->assertSame('100000.00', $revenueLine->debit);

        $entry->refresh();

        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertSame('100000.00', $entry->total_debit);
        $this->assertSame('100000.00', $entry->total_credit);
    }

    public function test_idempotency_mencegah_jurnal_ganda(): void
    {
        $service = app(JournalPostingService::class);
        $data = $this->journalData(['idempotency_key' => 'test-idempotent-1']);

        $first = $service->postJournal($data, $this->balancedLines());
        $second = $service->postJournal($data, $this->balancedLines());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, JournalEntry::query()->where('idempotency_key', 'test-idempotent-1')->count());
    }

    public function test_referensi_sumber_yang_sama_boleh_memiliki_beberapa_versi_jurnal(): void
    {
        $service = app(JournalPostingService::class);

        $service->postJournal(
            $this->journalData(['idempotency_key' => 'version-1']),
            $this->balancedLines()
        );

        $service->postJournal(
            $this->journalData(['idempotency_key' => 'version-2']),
            $this->balancedLines()
        );

        $this->assertSame(2, JournalEntry::query()
            ->where('reference_type', 'test')
            ->where('reference_id', '1')
            ->count());
    }

    public function test_periode_open_dibuat_otomatis_dan_tetap_open(): void
    {
        $this->postBalancedJournal();

        $period = FiscalPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail();

        $this->assertTrue($period->isOpen());
        $this->assertSame(1, FiscalPeriod::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBalancedJournal(array $overrides = [], ?array $lines = null): JournalEntry
    {
        return app(JournalPostingService::class)->postJournal(
            $this->journalData($overrides),
            $lines ?? $this->balancedLines()
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function journalData(array $overrides = []): array
    {
        return array_merge([
            'journal_date' => '2026-10-12',
            'description' => 'Jurnal uji',
            'reference_type' => 'test',
            'reference_id' => '1',
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function balancedLines(): array
    {
        return [
            [
                'chart_of_account_id' => $this->accountingAccount('1001')->getKey(),
                'debit' => 100000,
                'credit' => 0,
                'description' => 'Kas masuk',
            ],
            [
                'chart_of_account_id' => $this->accountingAccount('4000')->getKey(),
                'debit' => 0,
                'credit' => 100000,
                'description' => 'Pendapatan',
            ],
        ];
    }
}
