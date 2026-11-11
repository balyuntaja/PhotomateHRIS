<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $table = 'journal_lines';

    protected $fillable = [
        'journal_entry_id',
        'chart_of_account_id',
        'description',
        'debit',
        'credit',
        'line_order',
    ];

    protected $casts = [
        'journal_entry_id' => 'integer',
        'chart_of_account_id' => 'integer',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'line_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if (blank($line->journal_entry_id)) {
                throw new DomainException('Baris jurnal harus memiliki jurnal.');
            }

            $entry = $line->relationLoaded('journalEntry')
                ? $line->journalEntry
                : JournalEntry::query()->find($line->journal_entry_id);

            if (! $entry) {
                throw new DomainException('Jurnal baris tidak ditemukan.');
            }

            if ($entry->isPosted()) {
                throw new DomainException('Baris jurnal posted tidak dapat diubah.');
            }

            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);

            if ($debit < 0 || $credit < 0) {
                throw new DomainException('Nominal debit dan kredit tidak boleh negatif.');
            }

            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw new DomainException('Setiap baris harus memiliki salah satu: debit atau kredit.');
            }

            $line->debit = number_format($debit, 2, '.', '');
            $line->credit = number_format($credit, 2, '.', '');
            $line->line_order ??= 0;
        });

        static::deleting(function (self $line): void {
            $entry = $line->relationLoaded('journalEntry')
                ? $line->journalEntry
                : JournalEntry::query()->find($line->journal_entry_id);

            if ($entry?->isPosted()) {
                throw new DomainException('Baris jurnal posted tidak dapat dihapus.');
            }
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function getDebitFormattedAttribute(): string
    {
        return RupiahHelper::format($this->debit);
    }

    public function getCreditFormattedAttribute(): string
    {
        return RupiahHelper::format($this->credit);
    }
}
