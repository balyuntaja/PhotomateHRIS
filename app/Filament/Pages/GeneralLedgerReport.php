<?php

namespace App\Filament\Pages;

use App\Support\Accounting\AbstractAccountingReportPage;

class GeneralLedgerReport extends AbstractAccountingReportPage
{
    protected static string $reportKey = 'general_ledger';

    protected static ?string $navigationLabel = 'General Ledger';

    protected static ?string $title = 'General Ledger';

    protected static ?string $slug = 'akuntansi-general-ledger';

    protected static ?int $navigationSort = 11;
}
