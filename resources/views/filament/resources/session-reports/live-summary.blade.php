@php
    use Filament\Widgets\StatsOverviewWidget\Stat;

    $getFunc = (isset($get) && is_callable($get)) ? $get : fn($key) => null;
    $transactions = $getFunc('transactions') ?? [];
    $pricingService = app(\App\Services\PricingService::class);
    $reportDate = $getFunc('report_date');

    $totalSessions = 0;
    $totalCashAmount = 0;
    $totalCashSessions = 0;
    $totalQrisAmount = 0;
    $totalQrisSessions = 0;
    $issues = [];

    foreach ($transactions as $idx => $trx) {
        $count = (int) ($trx['session_count'] ?? 1);
        $method = strtoupper($trx['payment_method'] ?? 'CASH');

        if ($count < 1) {
            $issues[] = "Transaksi #" . ($idx + 1) . ": Jumlah sesi minimal 1";
            $count = 0;
        }

        $amt = $count > 0 ? $pricingService->calculateSessionPrice($count, $reportDate) : 0;
        $totalSessions += $count;

        if ($method === 'QRIS') {
            $totalQrisSessions += $count;
            $totalQrisAmount += $amt;
        } else {
            $totalCashSessions += $count;
            $totalCashAmount += $amt;
        }
    }

    $grandTotal = $totalCashAmount + $totalQrisAmount;
    $trxCount = count($transactions);

    $stats = [
        Stat::make('Total Sesi', number_format($totalSessions) . ' Sesi')
            ->description($trxCount . ' Transaksi')
            ->descriptionIcon('heroicon-m-camera')
            ->color('primary'),

        Stat::make('Tunai', 'Rp ' . number_format($totalCashAmount, 0, ',', '.'))
            ->description($totalCashSessions . ' Sesi tunai')
            ->descriptionIcon('heroicon-m-currency-dollar')
            ->color('warning'),

        Stat::make('QRIS', 'Rp ' . number_format($totalQrisAmount, 0, ',', '.'))
            ->description($totalQrisSessions . ' Sesi non-tunai')
            ->descriptionIcon('heroicon-m-qr-code')
            ->color('info'),

        Stat::make('Total Revenue', 'Rp ' . number_format($grandTotal, 0, ',', '.'))
            ->description($totalSessions . ' Sesi total')
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('success'),
    ];
@endphp

<div class="fi-wi-stats-overview grid gap-y-4">
    <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            {{ $stat }}
        @endforeach
    </div>

    @if(!empty($issues))
        <div class="rounded-xl bg-amber-50 p-4 border border-amber-200 dark:bg-amber-950/30 dark:border-amber-800 text-sm text-amber-800 dark:text-amber-200">
            <div class="font-bold mb-1">Catatan Validasi:</div>
            <ul class="list-disc list-inside space-y-1">
                @foreach($issues as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
