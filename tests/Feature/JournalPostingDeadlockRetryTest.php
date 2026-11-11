<?php

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\JournalPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class JournalPostingDeadlockRetryTest extends TestCase
{
    use AccountingTestHelpers;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_deadlock_saat_membuat_fiscal_period_di_retry(): void
    {
        $triggered = false;

        FiscalPeriod::creating(function (FiscalPeriod $period) use (&$triggered): void {
            if ($triggered || (int) $period->year !== 2027 || (int) $period->month !== 1) {
                return;
            }

            $triggered = true;

            throw new QueryException(
                'mysql',
                'insert into fiscal_periods',
                [],
                new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction')
            );
        });

        $journal = app(JournalPostingService::class)->postJournal([
            'journal_date' => '2027-01-15',
            'description' => 'Jurnal setelah deadlock periode',
            'idempotency_key' => 'simulated-deadlock-period',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 10000],
        ]);

        $this->assertTrue($triggered);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertDatabaseHas('fiscal_periods', ['year' => 2027, 'month' => 1]);
    }
}
