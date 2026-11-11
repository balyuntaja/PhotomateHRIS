<?php

namespace App\Filament\Pages;

use App\Support\Accounting\AbstractAccountingReportPage;

class ProfitLossReport extends AbstractAccountingReportPage
{
    protected static string $reportKey = 'profit_loss';

    protected static ?string $navigationLabel = 'Profit & Loss';

    protected static ?string $title = 'Profit & Loss';

    protected static ?string $slug = 'akuntansi-profit-loss';

    protected static ?int $navigationSort = 12;
}
