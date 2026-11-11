<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public function trialBalance(
        CarbonInterface $start,
        CarbonInterface $end,
        bool $includeZeroAccounts = false
    ): array {
        $opening = $this->aggregatesBefore($start->toDateString());
        $movement = $this->aggregatesBetween($start->toDateString(), $end->toDateString());
        $accounts = ChartOfAccount::query()->orderBy('code')->get();

        $rows = [];
        $totals = [
            'opening_debit' => 0,
            'opening_credit' => 0,
            'movement_debit' => 0,
            'movement_credit' => 0,
            'ending_debit' => 0,
            'ending_credit' => 0,
        ];

        foreach ($accounts as $account) {
            $accountId = (int) $account->getKey();
            $openingDebit = $this->cents(data_get($opening->get($accountId), 'debit', 0));
            $openingCredit = $this->cents(data_get($opening->get($accountId), 'credit', 0));
            $movementDebit = $this->cents(data_get($movement->get($accountId), 'debit', 0));
            $movementCredit = $this->cents(data_get($movement->get($accountId), 'credit', 0));

            $openingNet = $openingDebit - $openingCredit;
            $endingNet = $openingNet + $movementDebit - $movementCredit;

            $row = [
                'account_id' => $accountId,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'report_group' => $account->report_group,
                'opening_debit' => max(0, $openingNet),
                'opening_credit' => max(0, -$openingNet),
                'movement_debit' => $movementDebit,
                'movement_credit' => $movementCredit,
                'ending_debit' => max(0, $endingNet),
                'ending_credit' => max(0, -$endingNet),
            ];

            if (! $includeZeroAccounts
                && $row['opening_debit'] === 0
                && $row['opening_credit'] === 0
                && $row['movement_debit'] === 0
                && $row['movement_credit'] === 0
                && $row['ending_debit'] === 0
                && $row['ending_credit'] === 0) {
                continue;
            }

            $rows[] = $row;

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        $isBalanced = $totals['opening_debit'] === $totals['opening_credit']
            && $totals['movement_debit'] === $totals['movement_credit']
            && $totals['ending_debit'] === $totals['ending_credit'];

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'rows' => $rows,
            'totals' => $totals,
            'is_balanced' => $isBalanced,
        ];
    }

    public function generalLedger(
        int $accountId,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $journalNumber = null,
        ?string $sourceType = null
    ): array {
        $account = ChartOfAccount::query()->findOrFail($accountId);
        $before = $this->aggregatesBefore($start->toDateString())->get((int) $account->getKey());
        $openingDebit = $this->cents(data_get($before, 'debit', 0));
        $openingCredit = $this->cents(data_get($before, 'credit', 0));
        $openingSigned = $account->normal_balance === ChartOfAccount::NORMAL_DEBIT
            ? $openingDebit - $openingCredit
            : $openingCredit - $openingDebit;

        $query = $this->baseLineQuery()
            ->where('l.chart_of_account_id', $account->getKey())
            ->whereDate('j.journal_date', '>=', $start->toDateString())
            ->whereDate('j.journal_date', '<=', $end->toDateString())
            ->when(filled($journalNumber), fn (Builder $query): Builder => $query->where('j.journal_number', 'like', '%'.$journalNumber.'%'))
            ->when(filled($sourceType), function (Builder $query) use ($sourceType): void {
                if ($sourceType === 'manual') {
                    $query->whereNull('j.reference_type');
                } else {
                    $query->where('j.reference_type', $sourceType);
                }
            })
            ->orderBy('j.journal_date')
            ->orderBy('j.id')
            ->orderBy('l.line_order')
            ->orderBy('l.id');

        $lines = $query->get([
            'j.id as journal_entry_id',
            'j.journal_number',
            'j.journal_date',
            'j.description as journal_description',
            'j.reference_type',
            'j.reference_id',
            'j.status',
            'l.id as line_id',
            'l.description as line_description',
            'l.debit',
            'l.credit',
            'l.line_order',
        ]);

        $running = $openingSigned;
        $totalDebit = 0;
        $totalCredit = 0;
        $rows = [];

        foreach ($lines as $line) {
            $debit = $this->cents($line->debit);
            $credit = $this->cents($line->credit);
            $signed = $account->normal_balance === ChartOfAccount::NORMAL_DEBIT
                ? $debit - $credit
                : $credit - $debit;

            $running += $signed;
            $totalDebit += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'journal_entry_id' => (int) $line->journal_entry_id,
                'journal_number' => $line->journal_number,
                'journal_date' => $line->journal_date,
                'description' => $line->line_description ?: $line->journal_description,
                'reference_type' => $line->reference_type,
                'reference_id' => $line->reference_id,
                'source_label' => $this->sourceLabel($line->reference_type),
                'status' => $line->status,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        }

        return [
            'account' => $account,
            'account_id' => (int) $account->getKey(),
            'normal_balance' => $account->normal_balance,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'opening_balance' => $openingSigned,
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'ending_balance' => $running,
        ];
    }

    public function profitLoss(CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->profitLossForRange($start, $end);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthlyProfitLoss(CarbonInterface $start, CarbonInterface $end): array
    {
        $months = [];
        $cursor = Carbon::parse($start->toDateString())->startOfMonth();
        $last = Carbon::parse($end->toDateString())->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth()->greaterThan($end) ? Carbon::parse($end->toDateString()) : $cursor->copy()->endOfMonth();

            $summary = $this->profitLossForRange($monthStart, $monthEnd);
            $summary['period'] = $monthStart->format('Y-m');
            $months[] = $summary;

            $cursor->addMonth();
        }

        return $months;
    }

    public function balanceSheet(CarbonInterface $asOf): array
    {
        $date = $asOf->toDateString();
        $all = $this->aggregatesUpTo($date);
        $accounts = ChartOfAccount::query()->get()->keyBy(fn (ChartOfAccount $account): int => (int) $account->getKey());

        $sections = [
            'current_assets' => [],
            'non_current_assets' => [],
            'current_liabilities' => [],
            'long_term_liabilities' => [],
            'equity' => [],
        ];

        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquityAccounts = 0;

        foreach ($all as $accountId => $aggregate) {
            $account = $accounts->get((int) $accountId);

            if (! $account) {
                continue;
            }

            $debit = $this->cents($aggregate->debit);
            $credit = $this->cents($aggregate->credit);

            if ($account->type === ChartOfAccount::TYPE_ASSET) {
                $balance = $debit - $credit;
                $section = $account->report_group === 'fixed_asset' ? 'non_current_assets' : 'current_assets';
                $sections[$section][] = $this->balanceSheetRow($account, $balance);
                $totalAssets += $balance;
            } elseif ($account->type === ChartOfAccount::TYPE_LIABILITY) {
                $balance = $credit - $debit;
                $section = $account->report_group === 'long_term_liability' ? 'long_term_liabilities' : 'current_liabilities';
                $sections[$section][] = $this->balanceSheetRow($account, $balance);
                $totalLiabilities += $balance;
            } elseif ($account->type === ChartOfAccount::TYPE_EQUITY) {
                $balance = $credit - $debit;
                $sections['equity'][] = $this->balanceSheetRow($account, $balance);
                $totalEquityAccounts += $balance;
            }
        }

        $currentYearStart = Carbon::parse($date)->startOfYear()->toDateString();
        $priorEarnings = $this->pnlNetFromAggregates($this->aggregatesBefore($currentYearStart));
        $currentEarnings = $this->pnlNetFromAggregates($this->aggregatesBetween($currentYearStart, $date));
        $totalEquity = $totalEquityAccounts + $priorEarnings + $currentEarnings;
        $difference = $totalAssets - ($totalLiabilities + $totalEquity);

        return [
            'as_of' => $date,
            'sections' => $sections,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity_accounts' => $totalEquityAccounts,
            'prior_earnings' => $priorEarnings,
            'current_earnings' => $currentEarnings,
            'total_equity' => $totalEquity,
            'difference' => $difference,
            'is_balanced' => $difference === 0,
            'limitations' => [
                'Belum ada saldo awal/opening balance yang diimpor; laporan hanya memuat jurnal posted.',
                'Retained earnings dihitung dari akun P&L karena belum ada closing entries.',
                'Transaksi yang belum terposting tidak termasuk dalam laporan.',
            ],
        ];
    }

    public function cashFlow(CarbonInterface $start, CarbonInterface $end): array
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $cashAccountIds = $this->cashAccountIds();

        $beginningCash = $this->cashBalanceBefore($startDate, $cashAccountIds);
        $periodCashChange = $this->cashMovementBetween($startDate, $endDate, $cashAccountIds);

        $lines = $this->baseLineQuery()
            ->whereDate('j.journal_date', '>=', $startDate)
            ->whereDate('j.journal_date', '<=', $endDate)
            ->orderBy('j.journal_date')
            ->orderBy('j.id')
            ->orderBy('l.line_order')
            ->orderBy('l.id')
            ->get([
                'j.id as journal_entry_id',
                'j.journal_number',
                'j.journal_date',
                'j.description as journal_description',
                'j.reference_type',
                'j.reference_id',
                'l.chart_of_account_id',
                'l.description as line_description',
                'l.debit',
                'l.credit',
                'a.code as account_code',
                'a.name as account_name',
                'a.type as account_type',
                'a.report_group',
                'a.cash_flow_category',
                'a.id as account_id',
            ]);

        $grouped = $lines->groupBy('journal_entry_id');
        $accountMap = ChartOfAccount::query()->get()->keyBy(fn (ChartOfAccount $account): int => (int) $account->getKey());
        $rows = [];
        $totals = [
            ChartOfAccount::CASH_FLOW_OPERATING => 0,
            ChartOfAccount::CASH_FLOW_INVESTING => 0,
            ChartOfAccount::CASH_FLOW_FINANCING => 0,
            ChartOfAccount::CASH_FLOW_UNCLASSIFIED => 0,
        ];

        foreach ($grouped as $journalId => $journalLines) {
            $cashMovement = 0;
            $nonCash = [];
            $hasCashLine = false;

            foreach ($journalLines as $line) {
                $debit = $this->cents($line->debit);
                $credit = $this->cents($line->credit);

                if (in_array((int) $line->chart_of_account_id, $cashAccountIds, true)) {
                    $cashMovement += $debit - $credit;
                    $hasCashLine = true;
                } else {
                    $nonCash[] = $line;
                }
            }

            if (! $hasCashLine) {
                continue;
            }

            $allocated = 0;

            foreach ($nonCash as $line) {
                $account = $accountMap->get((int) $line->account_id);

                if (! $account) {
                    continue;
                }

                $category = $this->cashFlowCategoryFor($account);

                if ($category === ChartOfAccount::CASH_FLOW_CASH) {
                    $category = ChartOfAccount::CASH_FLOW_UNCLASSIFIED;
                }

                $amount = -1 * ($this->cents($line->debit) - $this->cents($line->credit));

                if ($amount === 0) {
                    continue;
                }

                $allocated += $amount;
                $totals[$category] += $amount;

                $rows[] = [
                    'category' => $category,
                    'category_label' => ChartOfAccount::CASH_FLOW_LABELS[$category] ?? $category,
                    'journal_entry_id' => (int) $journalId,
                    'journal_number' => $line->journal_number,
                    'journal_date' => $line->journal_date,
                    'description' => $line->line_description ?: $line->journal_description,
                    'reference_type' => $line->reference_type,
                    'reference_id' => $line->reference_id,
                    'source_label' => $this->sourceLabel($line->reference_type),
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'amount' => $amount,
                ];
            }

            $adjustment = $cashMovement - $allocated;

            if ($adjustment !== 0) {
                $totals[ChartOfAccount::CASH_FLOW_UNCLASSIFIED] += $adjustment;
                $rows[] = [
                    'category' => ChartOfAccount::CASH_FLOW_UNCLASSIFIED,
                    'category_label' => ChartOfAccount::CASH_FLOW_LABELS[ChartOfAccount::CASH_FLOW_UNCLASSIFIED],
                    'journal_entry_id' => (int) $journalId,
                    'journal_number' => $journalLines->first()->journal_number,
                    'journal_date' => $journalLines->first()->journal_date,
                    'description' => 'Unclassified cash movement',
                    'reference_type' => $journalLines->first()->reference_type,
                    'reference_id' => $journalLines->first()->reference_id,
                    'source_label' => $this->sourceLabel($journalLines->first()->reference_type),
                    'account_code' => '-',
                    'account_name' => 'Unclassified',
                    'amount' => $adjustment,
                ];
            }
        }

        $classifiedTotal = array_sum($totals);
        $difference = $periodCashChange - $classifiedTotal;

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'beginning_cash' => $beginningCash,
            'period_cash_change' => $periodCashChange,
            'ending_cash' => $beginningCash + $periodCashChange,
            'totals' => $totals,
            'rows' => $rows,
            'classified_total' => $classifiedTotal,
            'difference' => $difference,
            'is_reconciled' => $difference === 0,
            'cash_account_codes' => ChartOfAccount::query()
                ->whereIn('id', $cashAccountIds)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
        ];
    }

    public function cashFlowCategoryFor(ChartOfAccount $account): string
    {
        if (filled($account->cash_flow_category)
            && array_key_exists($account->cash_flow_category, ChartOfAccount::CASH_FLOW_LABELS)) {
            return $account->cash_flow_category;
        }

        $cashCodes = config('accounting.cash_flow.cash_coa_codes', []);

        if (in_array($account->code, $cashCodes, true)) {
            return ChartOfAccount::CASH_FLOW_CASH;
        }

        if (in_array($account->code, config('accounting.cash_flow.interest_coa_codes', []), true)) {
            return config('accounting.cash_flow.interest_paid_category', ChartOfAccount::CASH_FLOW_OPERATING);
        }

        return match ($account->type) {
            ChartOfAccount::TYPE_REVENUE,
            ChartOfAccount::TYPE_COGS,
            ChartOfAccount::TYPE_EXPENSE => ChartOfAccount::CASH_FLOW_OPERATING,
            ChartOfAccount::TYPE_ASSET => $account->report_group === 'fixed_asset'
                ? ChartOfAccount::CASH_FLOW_INVESTING
                : ChartOfAccount::CASH_FLOW_OPERATING,
            ChartOfAccount::TYPE_LIABILITY => $account->report_group === 'long_term_liability'
                ? ChartOfAccount::CASH_FLOW_FINANCING
                : ChartOfAccount::CASH_FLOW_OPERATING,
            ChartOfAccount::TYPE_EQUITY => ChartOfAccount::CASH_FLOW_FINANCING,
            default => ChartOfAccount::CASH_FLOW_UNCLASSIFIED,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function profitLossForRange(CarbonInterface $start, CarbonInterface $end): array
    {
        $aggregates = $this->aggregatesBetween($start->toDateString(), $end->toDateString());
        $accounts = ChartOfAccount::query()
            ->whereIn('type', [
                ChartOfAccount::TYPE_REVENUE,
                ChartOfAccount::TYPE_COGS,
                ChartOfAccount::TYPE_EXPENSE,
            ])
            ->get()
            ->keyBy(fn (ChartOfAccount $account): int => (int) $account->getKey());

        $sections = [
            'revenue' => [],
            'other_income' => [],
            'cogs' => [],
            'opex' => [],
            'other_expense' => [],
        ];

        foreach ($aggregates as $accountId => $aggregate) {
            $account = $accounts->get((int) $accountId);

            if (! $account) {
                continue;
            }

            $debit = $this->cents($aggregate->debit);
            $credit = $this->cents($aggregate->credit);
            $amount = $account->type === ChartOfAccount::TYPE_REVENUE
                ? $credit - $debit
                : $debit - $credit;

            if ($amount === 0) {
                continue;
            }

            if ($account->type === ChartOfAccount::TYPE_REVENUE) {
                $section = $account->report_group === 'other_income' ? 'other_income' : 'revenue';
            } elseif ($account->type === ChartOfAccount::TYPE_COGS) {
                $section = 'cogs';
            } else {
                $section = $account->report_group === 'other_expense' ? 'other_expense' : 'opex';
            }

            $sections[$section][] = [
                'account_id' => (int) $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'report_group' => $account->report_group,
                'amount' => $amount,
            ];
        }

        $sum = fn (string $section): int => array_sum(array_column($sections[$section], 'amount'));
        $revenue = $sum('revenue');
        $cogs = $sum('cogs');
        $opex = $sum('opex');
        $otherIncome = $sum('other_income');
        $otherExpense = $sum('other_expense');
        $grossProfit = $revenue - $cogs;
        $operatingProfit = $grossProfit - $opex;
        $netProfit = $operatingProfit + $otherIncome - $otherExpense;

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'sections' => $sections,
            'totals' => [
                'revenue' => $revenue,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'opex' => $opex,
                'operating_profit' => $operatingProfit,
                'other_income' => $otherIncome,
                'other_expense' => $otherExpense,
                'net_profit' => $netProfit,
            ],
        ];
    }

    /**
     * @return array{account_id: int, code: string, name: string, balance: int}
     */
    private function balanceSheetRow(ChartOfAccount $account, int $balance): array
    {
        return [
            'account_id' => (int) $account->getKey(),
            'code' => $account->code,
            'name' => $account->name,
            'report_group' => $account->report_group,
            'balance' => $balance,
        ];
    }

    private function pnlNetFromAggregates(Collection $aggregates): int
    {
        $accounts = ChartOfAccount::query()
            ->whereIn('type', [
                ChartOfAccount::TYPE_REVENUE,
                ChartOfAccount::TYPE_COGS,
                ChartOfAccount::TYPE_EXPENSE,
            ])
            ->get()
            ->keyBy(fn (ChartOfAccount $account): int => (int) $account->getKey());

        $net = 0;

        foreach ($aggregates as $accountId => $aggregate) {
            $account = $accounts->get((int) $accountId);

            if (! $account) {
                continue;
            }

            $debit = $this->cents($aggregate->debit);
            $credit = $this->cents($aggregate->credit);

            $net += $account->type === ChartOfAccount::TYPE_REVENUE
                ? $credit - $debit
                : -1 * ($debit - $credit);
        }

        return $net;
    }

    /**
     * @return Collection<int, object>
     */
    private function aggregatesBefore(string $date): Collection
    {
        return $this->aggregatesQuery()
            ->whereDate('j.journal_date', '<', $date)
            ->get()
            ->keyBy(fn ($row): int => (int) $row->chart_of_account_id);
    }

    /**
     * @return Collection<int, object>
     */
    private function aggregatesBetween(string $startDate, string $endDate): Collection
    {
        return $this->aggregatesQuery()
            ->whereDate('j.journal_date', '>=', $startDate)
            ->whereDate('j.journal_date', '<=', $endDate)
            ->get()
            ->keyBy(fn ($row): int => (int) $row->chart_of_account_id);
    }

    /**
     * @return Collection<int, object>
     */
    private function aggregatesUpTo(string $date): Collection
    {
        return $this->aggregatesQuery()
            ->whereDate('j.journal_date', '<=', $date)
            ->get()
            ->keyBy(fn ($row): int => (int) $row->chart_of_account_id);
    }

    private function aggregatesQuery(): Builder
    {
        return $this->baseLineQuery()
            ->select(
                'l.chart_of_account_id',
                DB::raw('COALESCE(SUM(l.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(l.credit), 0) as credit')
            )
            ->groupBy('l.chart_of_account_id');
    }

    private function baseLineQuery(): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->where('j.status', JournalEntry::STATUS_POSTED);
    }

    /**
     * @param  array<int, int>  $cashAccountIds
     */
    private function cashBalanceBefore(string $date, array $cashAccountIds): int
    {
        $query = $this->baseLineQuery()
            ->whereIn('l.chart_of_account_id', $cashAccountIds)
            ->whereDate('j.journal_date', '<', $date)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        return $this->cents($query->debit ?? 0) - $this->cents($query->credit ?? 0);
    }

    /**
     * @param  array<int, int>  $cashAccountIds
     */
    private function cashMovementBetween(string $startDate, string $endDate, array $cashAccountIds): int
    {
        $query = $this->baseLineQuery()
            ->whereIn('l.chart_of_account_id', $cashAccountIds)
            ->whereDate('j.journal_date', '>=', $startDate)
            ->whereDate('j.journal_date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        return $this->cents($query->debit ?? 0) - $this->cents($query->credit ?? 0);
    }

    /**
     * @return array<int, int>
     */
    private function cashAccountIds(): array
    {
        $codes = config('accounting.cash_flow.cash_coa_codes', []);

        return ChartOfAccount::query()
            ->where(function (EloquentBuilder $query) use ($codes): void {
                $query->whereIn('code', $codes)
                    ->orWhere('cash_flow_category', ChartOfAccount::CASH_FLOW_CASH);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function sourceLabel(?string $referenceType): string
    {
        return match ($referenceType) {
            'financial_transaction' => 'Transaksi Operasional',
            'invoice' => 'Invoice',
            'session_report' => 'Rekap Sesi',
            'luminash_csv' => 'Luminash CSV',
            null, '' => 'Manual / Tanpa Referensi',
            default => $referenceType,
        };
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
