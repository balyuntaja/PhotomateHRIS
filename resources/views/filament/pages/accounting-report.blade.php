<x-filament-panels::page>
    @php
        $format = fn (int $cents): string => \App\Utils\RupiahHelper::format($cents / 100);
        $signed = fn (int $cents): string => ($cents < 0 ? '- ' : '+ ') . \App\Utils\RupiahHelper::format(abs($cents) / 100);
        $journalUrl = fn (int $id): string => \App\Filament\Resources\JournalEntryResource::getUrl('view', ['record' => $id]);
        $canViewJournal = auth()->user()?->isSuperAdmin() || (auth()->user()?->can('view_any_journal_entry') ?? false);
    @endphp

    <div class="space-y-6">
        {{ $this->form }}

        <div class="text-sm text-gray-500 dark:text-gray-400">
            Laporan: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $reportTitle }}</span>
            &middot;
            Periode/Per tanggal:
            <span class="font-medium text-gray-700 dark:text-gray-200">
                {{ $start->translatedFormat('d M Y') }}
                @if($reportKey !== 'balance_sheet')
                    s.d. {{ $end->translatedFormat('d M Y') }}
                @endif
            </span>
        </div>

        @if($reportKey === 'trial_balance')
            <div class="flex items-center gap-3">
                <x-filament::badge :color="$report['is_balanced'] ? 'success' : 'danger'">
                    {{ $report['is_balanced'] ? 'BALANCE' : 'TIDAK BALANCE' }}
                </x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">Hanya jurnal posted yang dihitung.</span>
            </div>

            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2">Kode</th>
                                <th class="px-3 py-2">Nama Akun</th>
                                <th class="px-3 py-2">Tipe / Normal</th>
                                <th class="px-3 py-2 text-right">Opening Debit</th>
                                <th class="px-3 py-2 text-right">Opening Kredit</th>
                                <th class="px-3 py-2 text-right">Mutasi Debit</th>
                                <th class="px-3 py-2 text-right">Mutasi Kredit</th>
                                <th class="px-3 py-2 text-right">Ending Debit</th>
                                <th class="px-3 py-2 text-right">Ending Kredit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse($report['rows'] as $row)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $row['code'] }}</td>
                                    <td class="px-3 py-2">{{ $row['name'] }}</td>
                                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row['type'] }} / {{ $row['normal_balance'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['opening_debit']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['opening_credit']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['movement_debit']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['movement_credit']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['ending_debit']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['ending_credit']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">Tidak ada pergerakan pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold dark:border-gray-700 dark:bg-gray-900">
                            <tr>
                                <td class="px-3 py-2" colspan="3">TOTAL</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['totals']['opening_debit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['totals']['opening_credit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['totals']['movement_debit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['totals']['movement_credit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['totals']['ending_debit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['totals']['ending_credit']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>

        @elseif($reportKey === 'general_ledger')
            <x-filament::card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $report['account']->code }} — {{ $report['account']->name }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Normal balance: {{ $report['normal_balance'] }}</div>
                    </div>
                    <div class="text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Saldo awal:</span>
                        <span class="font-semibold">{{ $signed($report['opening_balance']) }}</span>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2">Nomor Jurnal</th>
                                <th class="px-3 py-2">Deskripsi</th>
                                <th class="px-3 py-2">Sumber</th>
                                <th class="px-3 py-2 text-right">Debit</th>
                                <th class="px-3 py-2 text-right">Kredit</th>
                                <th class="px-3 py-2 text-right">Saldo Berjalan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse($report['rows'] as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse($row['journal_date'])->translatedFormat('d M Y') }}</td>
                                    <td class="px-3 py-2 font-mono">
                                        @if($canViewJournal)
                                            <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ $journalUrl($row['journal_entry_id']) }}">{{ $row['journal_number'] }}</a>
                                        @else
                                            {{ $row['journal_number'] }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $row['description'] ?: '-' }}</td>
                                    <td class="px-3 py-2">{{ $row['source_label'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['debit']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $format($row['credit']) }}</td>
                                    <td class="px-3 py-2 text-right font-medium">{{ $signed($row['running_balance']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Tidak ada mutasi pada filter ini.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold dark:border-gray-700 dark:bg-gray-900">
                            <tr>
                                <td class="px-3 py-2" colspan="4">TOTAL MUTASI</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['total_debit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($report['total_credit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $signed($report['ending_balance']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>

        @elseif($reportKey === 'profit_loss')
            <div class="grid gap-4 md:grid-cols-4">
                <x-filament::card>
                    <div class="text-sm text-gray-500">Revenue</div>
                    <div class="mt-2 text-xl font-semibold text-success-600">{{ $format($report['totals']['revenue']) }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div class="text-sm text-gray-500">Gross Profit</div>
                    <div class="mt-2 text-xl font-semibold">{{ $format($report['totals']['gross_profit']) }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div class="text-sm text-gray-500">Operating Profit</div>
                    <div class="mt-2 text-xl font-semibold">{{ $format($report['totals']['operating_profit']) }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div class="text-sm text-gray-500">Net Profit / Loss</div>
                    <div class="mt-2 text-xl font-semibold {{ $report['totals']['net_profit'] < 0 ? 'text-danger-600' : 'text-success-600' }}">{{ $format($report['totals']['net_profit']) }}</div>
                </x-filament::card>
            </div>

            @foreach(['revenue' => 'Revenue', 'cogs' => 'COGS', 'opex' => 'Operating Expenses', 'other_income' => 'Other Income', 'other_expense' => 'Other Expenses'] as $sectionKey => $sectionLabel)
                <x-filament::section>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-base font-semibold">{{ $sectionLabel }}</h3>
                        <span class="font-semibold">{{ $format(array_sum(array_column($report['sections'][$sectionKey], 'amount'))) }}</span>
                    </div>
                    @if($report['sections'][$sectionKey] === [])
                        <div class="text-sm text-gray-500">Tidak ada saldo.</div>
                    @else
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($report['sections'][$sectionKey] as $row)
                                    <tr>
                                        <td class="px-3 py-2 font-mono">{{ $row['code'] }}</td>
                                        <td class="px-3 py-2">{{ $row['name'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $format($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            @endforeach

            <x-filament::section>
                <h3 class="mb-3 text-base font-semibold">Ringkasan Bulanan</h3>
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Periode</th>
                            <th class="px-3 py-2 text-right">Revenue</th>
                            <th class="px-3 py-2 text-right">COGS</th>
                            <th class="px-3 py-2 text-right">Gross</th>
                            <th class="px-3 py-2 text-right">OPEX</th>
                            <th class="px-3 py-2 text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($report['monthly'] as $month)
                            <tr>
                                <td class="px-3 py-2">{{ $month['period'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($month['totals']['revenue']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($month['totals']['cogs']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($month['totals']['gross_profit']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($month['totals']['opex']) }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ $format($month['totals']['net_profit']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

        @elseif($reportKey === 'balance_sheet')
            <div class="flex items-center gap-3">
                <x-filament::badge :color="$report['is_balanced'] ? 'success' : 'danger'">
                    {{ $report['is_balanced'] ? 'ASSETS = LIABILITIES + EQUITY' : 'TIDAK SEIMBANG' }}
                </x-filament::badge>
                @if(! $report['is_balanced'])
                    <span class="text-sm text-danger-600">Selisih: {{ $format($report['difference']) }}</span>
                @endif
            </div>

            @foreach(['current_assets' => 'Current Assets', 'non_current_assets' => 'Non-current Assets', 'current_liabilities' => 'Current Liabilities', 'long_term_liabilities' => 'Long-term Liabilities', 'equity' => 'Equity'] as $sectionKey => $sectionLabel)
                <x-filament::section>
                    <h3 class="mb-3 text-base font-semibold">{{ $sectionLabel }}</h3>
                    @if($report['sections'][$sectionKey] === [])
                        <div class="text-sm text-gray-500">Tidak ada saldo.</div>
                    @else
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($report['sections'][$sectionKey] as $row)
                                    <tr>
                                        <td class="px-3 py-2 font-mono">{{ $row['code'] }}</td>
                                        <td class="px-3 py-2">{{ $row['name'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $format($row['balance']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            @endforeach

            <x-filament::section>
                <h3 class="mb-3 text-base font-semibold">Earnings</h3>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr><td class="px-3 py-2">Current Period Earnings</td><td class="px-3 py-2 text-right">{{ $format($report['current_earnings']) }}</td></tr>
                        <tr><td class="px-3 py-2">Prior Earnings</td><td class="px-3 py-2 text-right">{{ $format($report['prior_earnings']) }}</td></tr>
                        <tr class="font-semibold"><td class="px-3 py-2">Total Equity</td><td class="px-3 py-2 text-right">{{ $format($report['total_equity']) }}</td></tr>
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::card>
                <div class="text-sm text-gray-500">{{ implode(' ', $report['limitations']) }}</div>
            </x-filament::card>

        @elseif($reportKey === 'cash_flow')
            <div class="grid gap-4 md:grid-cols-4">
                <x-filament::card>
                    <div class="text-sm text-gray-500">Operating</div>
                    <div class="mt-2 text-xl font-semibold">{{ $signed($report['totals']['operating']) }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div class="text-sm text-gray-500">Investing</div>
                    <div class="mt-2 text-xl font-semibold">{{ $signed($report['totals']['investing']) }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div class="text-sm text-gray-500">Financing</div>
                    <div class="mt-2 text-xl font-semibold">{{ $signed($report['totals']['financing']) }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div class="text-sm text-gray-500">Unclassified</div>
                    <div class="mt-2 text-xl font-semibold {{ $report['totals']['unclassified'] === 0 ? 'text-success-600' : 'text-warning-600' }}">{{ $signed($report['totals']['unclassified']) }}</div>
                </x-filament::card>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="$report['is_reconciled'] ? 'success' : 'danger'">
                    {{ $report['is_reconciled'] ? 'REKONSILIASI COCOK' : 'REKONSILIASI TIDAK COCOK' }}
                </x-filament::badge>
                <span class="text-sm text-gray-500">Saldo awal {{ $format($report['beginning_cash']) }}</span>
                <span class="text-sm text-gray-500">Perubahan {{ $signed($report['period_cash_change']) }}</span>
                <span class="text-sm text-gray-500">Saldo akhir {{ $format($report['ending_cash']) }}</span>
                <span class="text-sm text-gray-500">Akun kas: {{ implode(', ', $report['cash_account_codes']) }}</span>
            </div>

            @if($report['totals']['unclassified'] !== 0)
                <x-filament::card>
                    <div class="text-sm text-warning-600">Ada transaksi belum terklasifikasi. Baris unclassified tetap ditampilkan dan tidak dihilangkan.</div>
                </x-filament::card>
            @endif

            @foreach(['operating' => 'Operating Activities', 'investing' => 'Investing Activities', 'financing' => 'Financing Activities', 'unclassified' => 'Unclassified'] as $sectionKey => $sectionLabel)
                @php($sectionRows = collect($report['rows'])->where('category', $sectionKey)->values())
                <x-filament::section>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-base font-semibold">{{ $sectionLabel }}</h3>
                        <span class="font-semibold">{{ $signed($report['totals'][$sectionKey]) }}</span>
                    </div>
                    @if($sectionRows->isEmpty())
                        <div class="text-sm text-gray-500">Tidak ada aktivitas.</div>
                    @else
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Tanggal</th>
                                    <th class="px-3 py-2">Jurnal</th>
                                    <th class="px-3 py-2">Deskripsi</th>
                                    <th class="px-3 py-2">Akun Lawan</th>
                                    <th class="px-3 py-2 text-right">Nominal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($sectionRows as $row)
                                    <tr>
                                        <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse($row['journal_date'])->translatedFormat('d M Y') }}</td>
                                        <td class="px-3 py-2 font-mono">
                                            @if($canViewJournal)
                                                <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ $journalUrl($row['journal_entry_id']) }}">{{ $row['journal_number'] }}</a>
                                            @else
                                                {{ $row['journal_number'] }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">{{ $row['description'] ?: '-' }}</td>
                                        <td class="px-3 py-2">{{ $row['account_code'] }} — {{ $row['account_name'] }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ $signed($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
