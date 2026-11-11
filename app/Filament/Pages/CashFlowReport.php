<?php

namespace App\Filament\Pages;

use App\Support\Accounting\AbstractAccountingReportPage;

class CashFlowReport extends AbstractAccountingReportPage
{
    protected static string $reportKey = 'cash_flow';

    protected static ?string $navigationLabel = 'Cash Flow';

    protected static ?string $title = 'Cash Flow';

    protected static ?string $slug = 'akuntansi-cash-flow';

    protected static ?int $navigationSort = 14;
}
