<?php

namespace App\Filament\Pages;

use App\Support\Accounting\AbstractAccountingReportPage;

class BalanceSheetReport extends AbstractAccountingReportPage
{
    protected static string $reportKey = 'balance_sheet';

    protected static ?string $navigationLabel = 'Balance Sheet';

    protected static ?string $title = 'Balance Sheet';

    protected static ?string $slug = 'akuntansi-balance-sheet';

    protected static ?int $navigationSort = 13;
}
