<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditOrphanJurnal extends Command
{
    protected $signature = 'akuntansi:audit-jurnal
                            {--limit=50 : Maksimal sampel baris yang ditampilkan}
                            {--json : Keluarkan laporan dalam format JSON}';

    protected $description = 'Audit read-only integritas referensi, reversal, dan keseimbangan jurnal';

    private const REFERENCE_TABLES = [
        'financial_transaction' => 'financial_transactions',
        'invoice' => 'invoices',
        'session_report' => 'session_reports',
        'luminash_csv' => 'financial_transactions',
    ];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $issues = [];
        $samples = [];

        foreach (self::REFERENCE_TABLES as $referenceType => $table) {
            $orphanQuery = DB::table('journal_entries as j')
                ->where('j.reference_type', $referenceType)
                ->whereNotExists(function ($query) use ($table): void {
                    $query->select(DB::raw(1))
                        ->from($table.' as source')
                        ->whereColumn('source.id', 'j.reference_id');
                });

            $count = (clone $orphanQuery)->count();

            if ($count > 0) {
                $issues['orphan_reference_'.$referenceType] = $count;
                $samples['orphan_reference_'.$referenceType] = (clone $orphanQuery)
                    ->orderBy('j.id')
                    ->limit($limit)
                    ->get(['j.id', 'j.journal_number', 'j.reference_type', 'j.reference_id', 'j.status']);
            }
        }

        $knownTypes = array_keys(self::REFERENCE_TABLES);
        $unknownReferenceQuery = DB::table('journal_entries')
            ->whereNotNull('reference_type')
            ->whereNotIn('reference_type', $knownTypes);
        $unknownReferenceCount = (clone $unknownReferenceQuery)->count();

        if ($unknownReferenceCount > 0) {
            $issues['unknown_reference_type'] = $unknownReferenceCount;
            $samples['unknown_reference_type'] = (clone $unknownReferenceQuery)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'journal_number', 'reference_type', 'reference_id', 'status']);
        }

        $nullReferenceCount = DB::table('journal_entries')
            ->where(function ($query): void {
                $query->whereNull('reference_type')->orWhereNull('reference_id');
            })
            ->count();

        if ($nullReferenceCount > 0) {
            $issues['null_reference'] = $nullReferenceCount;
            $samples['null_reference'] = DB::table('journal_entries')
                ->where(function ($query): void {
                    $query->whereNull('reference_type')->orWhereNull('reference_id');
                })
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'journal_number', 'reference_type', 'reference_id', 'status']);
        }

        $deletedSourceQuery = DB::table('journal_entries as j')
            ->whereIn('j.reference_type', ['financial_transaction', 'luminash_csv'])
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('financial_transactions as source')
                    ->whereColumn('source.id', 'j.reference_id')
                    ->whereNotNull('source.deleted_at');
            });

        $deletedSourceCount = (clone $deletedSourceQuery)->count();

        if ($deletedSourceCount > 0) {
            $issues['source_deleted'] = $deletedSourceCount;
            $samples['source_deleted'] = (clone $deletedSourceQuery)
                ->orderBy('j.id')
                ->limit($limit)
                ->get(['j.id', 'j.journal_number', 'j.reference_type', 'j.reference_id', 'j.status']);
        }

        $reversalOrphanQuery = DB::table('journal_entries as j')
            ->whereNotNull('j.reversal_of_id')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('journal_entries as original')
                    ->whereColumn('original.id', 'j.reversal_of_id');
            });

        $reversalOrphanCount = (clone $reversalOrphanQuery)->count();

        if ($reversalOrphanCount > 0) {
            $issues['reversal_without_original'] = $reversalOrphanCount;
            $samples['reversal_without_original'] = (clone $reversalOrphanQuery)
                ->orderBy('j.id')
                ->limit($limit)
                ->get(['j.id', 'j.journal_number', 'j.reversal_of_id', 'j.status']);
        }

        $duplicateKeys = DB::table('journal_entries')
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('idempotency_key')
            ->having('jumlah', '>', 1)
            ->get();

        if ($duplicateKeys->isNotEmpty()) {
            $issues['duplicate_idempotency_key'] = $duplicateKeys->count();
            $samples['duplicate_idempotency_key'] = $duplicateKeys->take($limit);
        }

        $lineStats = DB::table('journal_lines')
            ->select(
                'journal_entry_id',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(debit), 0) as debit_sum'),
                DB::raw('COALESCE(SUM(credit), 0) as credit_sum')
            )
            ->groupBy('journal_entry_id');

        $journalStats = DB::table('journal_entries as j')
            ->leftJoinSub($lineStats, 'l', 'l.journal_entry_id', '=', 'j.id')
            ->get([
                'j.id',
                'j.journal_number',
                'j.status',
                'j.total_debit',
                'j.total_credit',
                DB::raw('COALESCE(l.line_count, 0) as line_count'),
                DB::raw('COALESCE(l.debit_sum, 0) as debit_sum'),
                DB::raw('COALESCE(l.credit_sum, 0) as credit_sum'),
            ]);

        $postedWithoutLines = $journalStats->filter(
            fn ($journal): bool => $journal->status === 'posted' && (int) $journal->line_count === 0
        );

        if ($postedWithoutLines->isNotEmpty()) {
            $issues['posted_without_lines'] = $postedWithoutLines->count();
            $samples['posted_without_lines'] = $postedWithoutLines->take($limit)->values();
        }

        $unbalanced = $journalStats->filter(function ($journal): bool {
            if ((int) $journal->line_count === 0) {
                return false;
            }

            $debit = (float) $journal->debit_sum;
            $credit = (float) $journal->credit_sum;

            return abs($debit - $credit) > 0.001
                || abs($debit - (float) $journal->total_debit) > 0.001
                || abs($credit - (float) $journal->total_credit) > 0.001;
        });

        if ($unbalanced->isNotEmpty()) {
            $issues['unbalanced_journal'] = $unbalanced->count();
            $samples['unbalanced_journal'] = $unbalanced->take($limit)->values();
        }

        $shortLines = $journalStats->filter(
            fn ($journal): bool => (int) $journal->line_count === 1
        );

        if ($shortLines->isNotEmpty()) {
            $issues['single_line_journal'] = $shortLines->count();
            $samples['single_line_journal'] = $shortLines->take($limit)->values();
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'audited_at' => now()->toIso8601String(),
                'issues' => $issues,
                'samples' => $samples,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('=== AUDIT ORPHAN & INTEGRITAS JURNAL (READ-ONLY) ===');
        $this->table(['Pemeriksaan', 'Jumlah', 'Status'], $this->summaryRows($issues));

        if ($issues === []) {
            $this->info('Tidak ada masalah integritas jurnal ditemukan.');

            return self::SUCCESS;
        }

        foreach ($samples as $issue => $rows) {
            $this->newLine();
            $this->warn('Sampel: '.$issue);
            $this->table(
                $this->sampleHeaders($rows),
                $this->sampleRows($rows)
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $issues
     * @return array<int, array<int, string>>
     */
    private function summaryRows(array $issues): array
    {
        $checks = [
            'orphan_reference_financial_transaction' => 'Referensi financial_transaction tidak ditemukan',
            'orphan_reference_invoice' => 'Referensi invoice tidak ditemukan',
            'orphan_reference_session_report' => 'Referensi session_report tidak ditemukan',
            'orphan_reference_luminash_csv' => 'Referensi luminash_csv tidak ditemukan',
            'unknown_reference_type' => 'Tipe referensi tidak dikenal',
            'null_reference' => 'Jurnal tanpa referensi sumber',
            'source_deleted' => 'Sumber transaksi sudah dihapus',
            'reversal_without_original' => 'Reversal tanpa jurnal asal',
            'duplicate_idempotency_key' => 'Idempotency key duplikat',
            'posted_without_lines' => 'Jurnal posted tanpa baris',
            'unbalanced_journal' => 'Jurnal tidak seimbang',
            'single_line_journal' => 'Jurnal hanya memiliki satu baris',
        ];

        $rows = [];

        foreach ($checks as $key => $label) {
            $rows[] = [$label, (string) ($issues[$key] ?? 0), ($issues[$key] ?? 0) > 0 ? 'MASALAH' : 'OK'];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, string>
     */
    private function sampleHeaders(Collection $rows): array
    {
        $first = $rows->first();

        if (! $first) {
            return ['-'];
        }

        return array_keys((array) $first);
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, array<int, string>>
     */
    private function sampleRows(Collection $rows): array
    {
        return $rows->map(fn ($row): array => array_map(
            fn ($value): string => (string) ($value ?? '-'),
            (array) $row
        ))->all();
    }
}
