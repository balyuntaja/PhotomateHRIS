<?php

namespace App\Filament\Pages;

use App\Support\Accounting\AbstractAccountingReportPage;

class TrialBalanceReport extends AbstractAccountingReportPage
{
    protected static string $reportKey = 'trial_balance';

    protected static ?string $navigationLabel = 'Trial Balance';

    protected static ?string $title = 'Trial Balance';

    protected static ?string $slug = 'akuntansi-trial-balance';

    protected static ?int $navigationSort = 10;
}
