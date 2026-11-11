<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingBackwardCompatibilityTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_transaksi_existing_tidak_berubah_setelah_jurnal_dibuat(): void
    {
        $transaction = $this->makeFinancialTransaction();
        $before = DB::table('financial_transactions')->where('id', $transaction->getKey())->first();
        $countBefore = DB::table('financial_transactions')->count();
        $sumBefore = (int) DB::table('financial_transactions')->sum('amount');

        app(JournalPostingService::class)->postJournal([
            'journal_date' => '2026-10-12',
            'description' => 'Jurnal tanpa integrasi transaksi',
            'reference_type' => 'test-backward-compatibility',
            'reference_id' => (string) $transaction->getKey(),
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 125000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 125000],
        ]);

        $after = DB::table('financial_transactions')->where('id', $transaction->getKey())->first();

        $this->assertEquals($before, $after);
        $this->assertSame($countBefore, DB::table('financial_transactions')->count());
        $this->assertSame($sumBefore, (int) DB::table('financial_transactions')->sum('amount'));
    }

    public function test_kategori_existing_tetap_33_dan_tidak_diubah(): void
    {
        $this->assertSame(33, FinancialCategory::query()->count());

        $expected = [
            ['name' => 'Kertas', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['name' => 'Tinta', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['name' => 'Pendapatan Booth', 'transaction_type' => FinancialCategory::TYPE_INCOME],
            ['name' => 'Pendapatan Lain-lain', 'transaction_type' => FinancialCategory::TYPE_INCOME],
        ];

        foreach ($expected as $row) {
            $this->assertDatabaseHas('financial_categories', $row);
        }
    }

    public function test_nama_akun_keuangan_existing_tidak_diubah(): void
    {
        $expectedNames = ['Photomate Express', 'Golio', 'Janus', 'Ekspansi'];

        foreach ($expectedNames as $name) {
            $this->assertSame(
                1,
                FinancialAccount::query()->where('name', $name)->count()
            );
        }

        $this->assertSame($expectedNames, FinancialAccount::query()
            ->orderBy('sort_order')
            ->pluck('name')
            ->all());
    }
}
