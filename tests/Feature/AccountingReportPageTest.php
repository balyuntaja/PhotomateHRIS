<?php

namespace Tests\Feature;

use App\Filament\Pages\BalanceSheetReport;
use App\Filament\Pages\CashFlowReport;
use App\Filament\Pages\GeneralLedgerReport;
use App\Filament\Pages\ProfitLossReport;
use App\Filament\Pages\TrialBalanceReport;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingReportPageTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected Karyawan $admin;

    protected Karyawan $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();

        $this->admin = $this->makeKaryawan('K0001', 'R01');
        $this->crew = $this->makeKaryawan('K0002', 'R07');

        $this->actingAs($this->admin);

        $account = FinancialAccount::query()->where('name', 'Ekspansi')->firstOrFail();

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 1000000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $account->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pendapatan halaman laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 200000,
            'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $account->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => now()->toDateString(),
            'description' => 'Beban halaman laporan',
        ]);
    }

    public function test_admin_dapat_membuka_lima_halaman_laporan(): void
    {
        Livewire::test(TrialBalanceReport::class)
            ->assertSuccessful()
            ->assertSee('Trial Balance')
            ->assertSee('BALANCE');

        Livewire::test(GeneralLedgerReport::class)
            ->assertSuccessful()
            ->assertSee('General Ledger')
            ->assertSee('Saldo awal');

        Livewire::test(ProfitLossReport::class)
            ->assertSuccessful()
            ->assertSee('Profit & Loss')
            ->assertSee('Net Profit / Loss');

        Livewire::test(BalanceSheetReport::class)
            ->assertSuccessful()
            ->assertSee('Balance Sheet')
            ->assertSee('ASSETS = LIABILITIES + EQUITY');

        Livewire::test(CashFlowReport::class)
            ->assertSuccessful()
            ->assertSee('Cash Flow')
            ->assertSee('REKONSILIASI COCOK');
    }

    public function test_crew_tidak_dapat_membuka_halaman_laporan(): void
    {
        $this->actingAs($this->crew);

        $this->assertFalse(TrialBalanceReport::canAccess());

        $this->get(TrialBalanceReport::getUrl())->assertForbidden();
        $this->get(GeneralLedgerReport::getUrl())->assertForbidden();
        $this->get(ProfitLossReport::getUrl())->assertForbidden();
        $this->get(BalanceSheetReport::getUrl())->assertForbidden();
        $this->get(CashFlowReport::getUrl())->assertForbidden();
    }
}
