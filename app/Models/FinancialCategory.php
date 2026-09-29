<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialCategory extends Model
{
    use HasFactory;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    protected $table = 'financial_categories';

    protected $fillable = [
        'name',
        'group_name',
        'transaction_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function typeOptions(): array
    {
        return [
            self::TYPE_INCOME => 'Pemasukan',
            self::TYPE_EXPENSE => 'Pengeluaran',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('transaction_type', $type);
    }

    public function getTypeLabelAttribute(): string
    {
        return static::typeOptions()[$this->transaction_type] ?? $this->transaction_type;
    }

    public function isUsed(): bool
    {
        return $this->transactions()->exists();
    }
}
