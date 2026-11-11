<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Karyawan;
use DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JournalPostingService
{
    /**
     * Membuat jurnal draft. Idempotency key yang sama akan mengembalikan
     * jurnal yang sudah ada tanpa membuat jurnal baru.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(array $data, array $lines, ?Karyawan $actor = null): JournalEntry
    {
        $idempotencyKey = $this->normalizeIdempotencyKey($data['idempotency_key'] ?? null);

        $operation = function () use ($data, $lines, $actor, $idempotencyKey): JournalEntry {
            $journalDate = $this->resolveDate($data['journal_date'] ?? null);

            if ($idempotencyKey !== null) {
                $existing = JournalEntry::query()
                    ->with(['lines.chartOfAccount', 'fiscalPeriod'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $referenceType = $this->normalizeNullableString($data['reference_type'] ?? null, 50, 'reference_type');
            $referenceId = $this->normalizeNullableString($data['reference_id'] ?? null, 100, 'reference_id');
            $this->assertReferencePair($referenceType, $referenceId);

            $period = FiscalPeriod::ensureOpenForDate($journalDate);
            [$normalizedLines, $totalDebitCents, $totalCreditCents] = $this->normalizeInputLines($lines);

            $entry = JournalEntry::create([
                'journal_number' => $this->nextJournalNumber($journalDate),
                'journal_date' => $journalDate->toDateString(),
                'description' => $data['description'] ?? null,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'status' => JournalEntry::STATUS_DRAFT,
                'fiscal_period_id' => $period->getKey(),
                'total_debit' => $this->decimalFromCents($totalDebitCents),
                'total_credit' => $this->decimalFromCents($totalCreditCents),
                'created_by' => $data['created_by'] ?? $actor?->karyawan_id ?? Auth::id(),
                'idempotency_key' => $idempotencyKey,
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
            ]);

            $entry->lines()->createMany($normalizedLines);

            return $entry->load(['lines.chartOfAccount', 'fiscalPeriod']);
        };

        if (DB::transactionLevel() > 0) {
            $attempt = 0;

            while (true) {
                try {
                    return $operation();
                } catch (QueryException $exception) {
                    $attempt++;

                    if ($idempotencyKey !== null) {
                        $existing = JournalEntry::query()
                            ->with(['lines.chartOfAccount', 'fiscalPeriod'])
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();

                        if ($existing) {
                            return $existing;
                        }
                    }

                    if (! $this->isUniqueViolation($exception) || $attempt >= 3) {
                        throw $exception;
                    }

                    usleep(50000 * $attempt);
                }
            }
        }

        $attempt = 0;

        while (true) {
            try {
                return DB::transaction($operation);
            } catch (QueryException|DeadlockException $exception) {
                $attempt++;

                if ($idempotencyKey !== null) {
                    $existing = JournalEntry::query()
                        ->with(['lines.chartOfAccount', 'fiscalPeriod'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing) {
                        return $existing;
                    }
                }

                if (! $this->isRetryableDatabaseError($exception) || $attempt >= 3) {
                    throw $exception;
                }

                usleep(50000 * $attempt);
            }
        }
    }

    /**
     * Membuat lalu memposting jurnal dalam satu alur eksplisit.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function postJournal(array $data, array $lines, ?Karyawan $actor = null): JournalEntry
    {
        $entry = $this->createDraft($data, $lines, $actor);

        return $entry->isPosted() ? $entry : $this->post($entry, $actor);
    }

    /**
     * Memposting jurnal draft. Validasi periode dan akun dilakukan ulang
     * untuk mencegah perubahan kondisi antara create dan post.
     */
    public function post(JournalEntry $entry, ?Karyawan $actor = null): JournalEntry
    {
        $operation = function () use ($entry, $actor): JournalEntry {
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isPosted()) {
                return $locked->load(['lines.chartOfAccount', 'fiscalPeriod']);
            }

            $period = $locked->fiscalPeriod()->first();

            if (! $period || ! $period->isOpen()) {
                throw new DomainException('Periode akuntansi sudah ditutup.');
            }

            [$totalDebitCents, $totalCreditCents] = $this->validateStoredLines($locked);

            $locked->forceFill([
                'status' => JournalEntry::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $actor?->karyawan_id ?? Auth::id(),
                'total_debit' => $this->decimalFromCents($totalDebitCents),
                'total_credit' => $this->decimalFromCents($totalCreditCents),
            ])->save();

            return $locked->refresh()->load(['lines.chartOfAccount', 'fiscalPeriod']);
        };

        if (DB::transactionLevel() > 0) {
            return $operation();
        }

        return DB::transaction($operation, 3);
    }

    /**
     * Membuat jurnal reversal yang berlawanan dari jurnal posted.
     * Jurnal asli tidak diubah dan tidak dihapus.
     */
    public function reverse(
        JournalEntry $entry,
        ?string $description = null,
        ?string $journalDate = null,
        ?Karyawan $actor = null
    ): JournalEntry {
        $operation = function () use ($entry, $description, $journalDate, $actor): JournalEntry {
            $original = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $original->isPosted()) {
                throw new DomainException('Hanya jurnal posted yang dapat direversal.');
            }

            $idempotencyKey = 'reversal:'.$original->getKey();

            $existing = JournalEntry::query()
                ->with(['lines.chartOfAccount', 'fiscalPeriod'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing->isPosted() ? $existing : $this->post($existing, $actor);
            }

            $original->load('lines');

            if ($original->lines->isEmpty()) {
                throw new DomainException('Jurnal asli tidak memiliki baris.');
            }

            $reversalDate = $this->resolveDate($journalDate);
            $reversalLines = $original->lines
                ->values()
                ->map(fn (JournalLine $line, int $index): array => [
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'description' => $line->description,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'line_order' => $line->line_order ?: $index,
                ])
                ->all();

            $reversal = $this->createDraft([
                'journal_date' => $reversalDate->toDateString(),
                'description' => $description ?: 'Reversal jurnal '.$original->journal_number,
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'idempotency_key' => $idempotencyKey,
                'reversal_of_id' => $original->getKey(),
            ], $reversalLines, $actor);

            return $reversal->isPosted() ? $reversal : $this->post($reversal, $actor);
        };

        if (DB::transactionLevel() > 0) {
            return $operation();
        }

        return DB::transaction($operation, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}
     */
    private function normalizeInputLines(array $lines): array
    {
        if (count($lines) < 2) {
            throw new DomainException('Jurnal harus memiliki minimal dua baris.');
        }

        $normalized = [];
        $totalDebitCents = 0;
        $totalCreditCents = 0;

        foreach (array_values($lines) as $index => $line) {
            $accountId = $line['chart_of_account_id'] ?? null;

            if (blank($accountId)) {
                throw new DomainException('Setiap baris jurnal harus memiliki akun.');
            }

            $account = ChartOfAccount::query()->find($accountId);

            if (! $account) {
                throw new DomainException('Akun jurnal tidak ditemukan.');
            }

            if (! $account->is_active) {
                throw new DomainException('Akun '.$account->code.' tidak aktif dan tidak dapat diposting.');
            }

            $debitCents = $this->cents($line['debit'] ?? 0);
            $creditCents = $this->cents($line['credit'] ?? 0);

            $this->assertLineSide($debitCents, $creditCents);

            $totalDebitCents += $debitCents;
            $totalCreditCents += $creditCents;

            $normalized[] = [
                'chart_of_account_id' => $account->getKey(),
                'description' => $line['description'] ?? null,
                'debit' => $this->decimalFromCents($debitCents),
                'credit' => $this->decimalFromCents($creditCents),
                'line_order' => $line['line_order'] ?? $index,
            ];
        }

        $this->assertBalanced($totalDebitCents, $totalCreditCents);

        return [$normalized, $totalDebitCents, $totalCreditCents];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function validateStoredLines(JournalEntry $entry): array
    {
        $lines = $entry->lines()->with('chartOfAccount')->get();

        if ($lines->count() < 2) {
            throw new DomainException('Jurnal harus memiliki minimal dua baris.');
        }

        $totalDebitCents = 0;
        $totalCreditCents = 0;

        foreach ($lines as $line) {
            $account = $line->chartOfAccount;

            if (! $account) {
                throw new DomainException('Akun jurnal tidak ditemukan.');
            }

            if (! $account->is_active) {
                throw new DomainException('Akun '.$account->code.' tidak aktif dan tidak dapat diposting.');
            }

            $debitCents = $this->cents($line->debit);
            $creditCents = $this->cents($line->credit);

            $this->assertLineSide($debitCents, $creditCents);

            $totalDebitCents += $debitCents;
            $totalCreditCents += $creditCents;
        }

        $this->assertBalanced($totalDebitCents, $totalCreditCents);

        return [$totalDebitCents, $totalCreditCents];
    }

    private function assertLineSide(int $debitCents, int $creditCents): void
    {
        if ($debitCents < 0 || $creditCents < 0) {
            throw new DomainException('Nominal debit dan kredit tidak boleh negatif.');
        }

        if (($debitCents > 0 && $creditCents > 0) || ($debitCents <= 0 && $creditCents <= 0)) {
            throw new DomainException('Setiap baris harus memiliki tepat satu sisi: debit atau kredit.');
        }
    }

    private function assertBalanced(int $totalDebitCents, int $totalCreditCents): void
    {
        if ($totalDebitCents !== $totalCreditCents) {
            throw new DomainException('Total debit dan kredit harus sama.');
        }

        if ($totalDebitCents <= 0) {
            throw new DomainException('Total jurnal harus lebih besar dari nol.');
        }
    }

    private function assertReferencePair(?string $referenceType, ?string $referenceId): void
    {
        if (blank($referenceType) !== blank($referenceId)) {
            throw new DomainException('reference_type dan reference_id harus diisi bersama.');
        }
    }

    private function resolveDate(mixed $value): Carbon
    {
        try {
            return Carbon::parse($value ?: now())->startOfDay();
        } catch (\Throwable) {
            throw new DomainException('Tanggal jurnal tidak valid.');
        }
    }

    private function normalizeIdempotencyKey(mixed $value): ?string
    {
        $key = trim((string) $value);

        if ($key === '') {
            return null;
        }

        if (strlen($key) > 100) {
            throw new DomainException('Idempotency key maksimal 100 karakter.');
        }

        return $key;
    }

    private function normalizeNullableString(mixed $value, int $maxLength, string $field): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength) {
            throw new DomainException($field.' maksimal '.$maxLength.' karakter.');
        }

        return $value;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function decimalFromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function isRetryableDatabaseError(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return ($exception instanceof QueryException && $this->isUniqueViolation($exception))
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'deadlock detected')
            || str_contains($message, 'Serialization failure')
            || str_contains($message, 'Lock wait timeout');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'duplicate key value violates unique constraint');
    }

    private function nextJournalNumber(Carbon $journalDate): string
    {
        $prefix = 'JE-'.$journalDate->format('Ym').'-';

        $lastNumber = JournalEntry::query()
            ->where('journal_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('journal_number')
            ->value('journal_number');

        $sequence = $lastNumber ? ((int) substr($lastNumber, -4)) + 1 : 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $exists = JournalEntry::query()->where('journal_number', $candidate)->exists();
            $sequence++;
        } while ($exists);

        return $candidate;
    }
}
