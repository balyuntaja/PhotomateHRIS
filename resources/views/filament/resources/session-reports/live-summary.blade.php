@php
    use Filament\Widgets\StatsOverviewWidget\Stat;
    use App\Models\Cabang;
    use App\Models\Karyawan;

    $getFunc = (isset($get) && is_callable($get)) ? $get : fn($key) => null;
    $transactions = $getFunc('transactions') ?? [];
    $pricingService = app(\App\Services\PricingService::class);
    $bonusService = app(\App\Services\BranchBonusService::class);
    $reportDate = $getFunc('/report_date');
    $cabangId = $getFunc('/cabang_id');
    $selectedCrews = $getFunc('/crews') ?? [];

    $totalSessions = 0;
    $totalCashAmount = 0;
    $totalCashSessions = 0;
    $totalQrisAmount = 0;
    $totalQrisSessions = 0;
    $issues = [];

    foreach ($transactions as $idx => $trx) {
        $amt = (float) ($trx['amount'] ?? 0);
        $method = strtoupper($trx['payment_method'] ?? 'CASH');

        if ($amt <= 0) {
            if (!empty($trx['session_count']) && (int) $trx['session_count'] > 0) {
                $count = (int) $trx['session_count'];
                $amt = $pricingService->calculateSessionPrice($count);
            } else {
                $issues[] = "Transaksi #" . ($idx + 1) . ": Nominal harga wajib diisi.";
                $count = 0;
            }
        } else {
            $count = $pricingService->calculateSessionsFromPrice($amt);
            if ($count < 1) {
                $issues[] = "Transaksi #" . ($idx + 1) . ": Nominal Rp " . number_format($amt, 0, ',', '.') . " tidak valid.";
            }
        }

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
        Stat::make('Total Sesi', number_format($trxCount) . ' Sesi')
            ->description($totalSessions . ' Lembar')
            ->descriptionIcon('heroicon-m-camera')
            ->color('primary'),

        Stat::make('Tunai', 'Rp ' . number_format($totalCashAmount, 0, ',', '.'))
            ->description($totalCashSessions . ' Lembar tunai')
            ->descriptionIcon('heroicon-m-currency-dollar')
            ->color('warning'),

        Stat::make('QRIS', 'Rp ' . number_format($totalQrisAmount, 0, ',', '.'))
            ->description($totalQrisSessions . ' Lembar non-tunai')
            ->descriptionIcon('heroicon-m-qr-code')
            ->color('info'),

        Stat::make('Total Revenue', 'Rp ' . number_format($grandTotal, 0, ',', '.'))
            ->description($totalSessions . ' Lembar total')
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('success'),
    ];

    // Check Newspaper Janus Bonus Eligibility
    $isJanus = $bonusService->isEligibleBranch(null, $cabangId);
    $crewCount = is_array($selectedCrews) ? count($selectedCrews) : 0;
    $bonusInfo = $bonusService->calculateDailyBonus($trxCount, $crewCount, null, $cabangId);

    $crewModels = collect();
    if ($isJanus && $crewCount > 0) {
        $crewModels = Karyawan::whereIn('karyawan_id', $selectedCrews)->get();
    }
@endphp

<div class="fi-wi-stats-overview grid gap-y-4">
    <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            {{ $stat }}
        @endforeach
    </div>

    {{-- Newspaper Janus Bonus Section --}}
    @if($isJanus)
        @if($bonusInfo['target_reached'])
            <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-900 dark:text-emerald-100">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-600 text-white font-bold text-sm">✓</span>
                        <div>
                            <span class="font-bold text-sm sm:text-base text-emerald-800 dark:text-emerald-200">
                                BONUS CREW NEWSPAPER JANUS: RP 50.000 AKTIF!
                            </span>
                            <p class="text-xs text-emerald-700 dark:text-emerald-300">
                                Target &ge; 30 sesi harian tercapai (Total: <strong>{{ $trxCount }} sesi</strong>). Bonus Rp 50.000 otomatis aktif.
                            </p>
                        </div>
                    </div>
                    <span class="px-3 py-1 bg-emerald-600 text-white text-xs font-bold rounded-full shadow-sm">
                        Target 30 Sesi Tercapai
                    </span>
                </div>

                <div class="mt-3 pt-3 border-t border-emerald-200 dark:border-emerald-800/60 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                    <div>
                        <span class="text-emerald-700 dark:text-emerald-300">Jumlah Crew Bertugas:</span>
                        <strong class="text-emerald-900 dark:text-emerald-100">{{ $crewCount }} orang</strong>
                        <div class="mt-1">
                            <span class="text-emerald-700 dark:text-emerald-300">Bonus per Crew:</span>
                            <span class="font-bold font-mono text-sm text-emerald-700 dark:text-emerald-300">
                                Rp {{ number_format($bonusInfo['bonus_per_crew'], 0, ',', '.') }}
                            </span>
                            @if($crewCount > 2)
                                <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-normal">(Rp 50.000 ÷ {{ $crewCount }})</span>
                            @elseif($crewCount === 2)
                                <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-normal">(Rp 50.000 ÷ 2)</span>
                            @endif
                        </div>
                    </div>

                    @if($crewModels->isNotEmpty())
                    <div class="bg-white/60 dark:bg-gray-900/40 p-2.5 rounded-lg border border-emerald-200 dark:border-emerald-800/40">
                        <span class="font-semibold text-emerald-800 dark:text-emerald-200 block mb-1">Rincian Penerima Bonus:</span>
                        <ul class="space-y-1">
                            @foreach($crewModels as $crew)
                            <li class="flex justify-between items-center text-[11px]">
                                <span>• {{ $crew->nama_lengkap }}</span>
                                <strong class="font-mono text-emerald-700 dark:text-emerald-300">Rp {{ number_format($bonusInfo['bonus_per_crew'], 0, ',', '.') }}</strong>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                </div>
            </div>
        @else
            <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-100">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-500 text-white font-bold text-sm">!</span>
                        <div>
                            <span class="font-bold text-sm sm:text-base text-amber-900 dark:text-amber-200">
                                Target Bonus Harian Newspaper Janus: {{ $trxCount }} / 30 Sesi
                            </span>
                            <p class="text-xs text-amber-700 dark:text-amber-300">
                                Kurang <strong>{{ $bonusInfo['remaining_sessions'] }} sesi lagi</strong> untuk mendapatkan bonus Rp 50.000 untuk crew bertugas hari ini.
                            </p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 bg-amber-200 dark:bg-amber-900/60 text-amber-900 dark:text-amber-200 text-xs font-semibold rounded-full">
                        {{ $trxCount }}/30 Sesi
                    </span>
                </div>

                @if($crewCount > 0)
                <div class="mt-2.5 text-xs text-amber-800 dark:text-amber-300 flex items-center gap-1">
                    <span>Estimasi jika target 30 sesi tercapai: masing-masing dari {{ $crewCount }} crew menerima</span>
                    <strong class="font-mono">Rp {{ number_format(round(50000 / $crewCount), 0, ',', '.') }}</strong>.
                </div>
                @endif
            </div>
        @endif
    @endif

    @if(!empty($issues))
        <div class="rounded-xl bg-rose-50 p-4 border border-rose-200 dark:bg-rose-950/30 dark:border-rose-800 text-sm text-rose-800 dark:text-rose-200">
            <div class="font-bold mb-1">Catatan Validasi:</div>
            <ul class="list-disc list-inside space-y-1">
                @foreach($issues as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
