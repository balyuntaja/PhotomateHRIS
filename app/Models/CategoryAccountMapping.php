<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAccountMapping extends Model
{
    protected $table = 'category_account_mappings';

    protected $fillable = [
        'financial_category_id',
        'financial_parent_category_id',
        'transaction_type',
        'debit_account_id',
        'credit_account_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'financial_category_id' => 'integer',
        'financial_parent_category_id' => 'integer',
        'debit_account_id' => 'integer',
        'credit_account_id' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $hasCategory = filled($mapping->financial_category_id);
            $hasParent = filled($mapping->financial_parent_category_id);

            if ($hasCategory === $hasParent) {
                throw new DomainException(
                    'Mapping harus memilih salah satu: sub kategori atau kategori utama.'
                );
            }

            if (! in_array($mapping->transaction_type, [FinancialCategory::TYPE_INCOME, FinancialCategory::TYPE_EXPENSE], true)) {
                throw new DomainException('Tipe transaksi mapping tidak valid.');
            }

            if ($hasCategory) {
                $category = FinancialCategory::query()->find($mapping->financial_category_id);

                if (! $category) {
                    throw new DomainException('Sub kategori mapping tidak ditemukan.');
                }

                if ($category->transaction_type !== $mapping->transaction_type) {
                    throw new DomainException(
                        'Tipe transaksi mapping harus sama dengan tipe sub kategori.'
                    );
                }
            } else {
                $parent = FinancialParentCategory::query()->find($mapping->financial_parent_category_id);

                if (! $parent) {
                    throw new DomainException('Kategori utama mapping tidak ditemukan.');
                }
            }

            $duplicate = static::query()
                ->when($mapping->exists, fn (Builder $query): Builder => $query->whereKeyNot($mapping->getKey()))
                ->when(
                    $hasCategory,
                    fn (Builder $query): Builder => $query->where('financial_category_id', $mapping->financial_category_id),
                    fn (Builder $query): Builder => $query->where('financial_parent_category_id', $mapping->financial_parent_category_id),
                )
                ->exists();

            if ($duplicate) {
                throw new DomainException('Kategori tersebut sudah memiliki mapping.');
            }

            $debitAccount = $mapping->debit_account_id
                ? ChartOfAccount::query()->find($mapping->debit_account_id)
                : null;
            $creditAccount = $mapping->credit_account_id
                ? ChartOfAccount::query()->find($mapping->credit_account_id)
                : null;

            if (! $debitAccount && ! $creditAccount) {
                throw new DomainException('Mapping harus memiliki minimal satu akun debit atau kredit.');
            }

            if ($debitAccount && $creditAccount && (int) $debitAccount->getKey() === (int) $creditAccount->getKey()) {
                throw new DomainException('Akun debit dan kredit tidak boleh sama.');
            }

            foreach (['debit' => $debitAccount, 'kredit' => $creditAccount] as $label => $account) {
                if ($account && ! $account->is_active) {
                    throw new DomainException('Akun '.$label.' mapping harus aktif.');
                }
            }

            if ($mapping->transaction_type === FinancialCategory::TYPE_INCOME) {
                if (! $creditAccount) {
                    throw new DomainException('Mapping income wajib memiliki akun kredit.');
                }

                if (! in_array($creditAccount->type, [
                    ChartOfAccount::TYPE_REVENUE,
                    ChartOfAccount::TYPE_ASSET,
                    ChartOfAccount::TYPE_LIABILITY,
                    ChartOfAccount::TYPE_EQUITY,
                ], true)) {
                    throw new DomainException('Akun kredit mapping income harus bertipe Pendapatan, Aset, Liabilitas, atau Ekuitas.');
                }

                if ($debitAccount && $debitAccount->type !== ChartOfAccount::TYPE_ASSET) {
                    throw new DomainException('Akun debit mapping income harus bertipe Aset.');
                }
            } else {
                if (! $debitAccount) {
                    throw new DomainException('Mapping expense wajib memiliki akun debit.');
                }

                if (! in_array($debitAccount->type, [
                    ChartOfAccount::TYPE_EXPENSE,
                    ChartOfAccount::TYPE_COGS,
                    ChartOfAccount::TYPE_LIABILITY,
                    ChartOfAccount::TYPE_ASSET,
                    ChartOfAccount::TYPE_EQUITY,
                ], true)) {
                    throw new DomainException('Akun debit mapping expense harus bertipe Beban, HPP, Liabilitas, Aset, atau Ekuitas.');
                }

                if ($creditAccount && ! in_array($creditAccount->type, [ChartOfAccount::TYPE_ASSET, ChartOfAccount::TYPE_LIABILITY], true)) {
                    throw new DomainException('Akun kredit mapping expense harus bertipe Aset atau Liabilitas.');
                }
            }
        });
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
    }

    public function financialParentCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialParentCategory::class, 'financial_parent_category_id');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Resolusi fallback yang aman: exact sub kategori lebih dulu, lalu parent.
     * Mapping nonaktif atau tidak lengkap tidak boleh menghasilkan jurnal.
     */
    public static function resolveForCategory(?FinancialCategory $category): ?self
    {
        if (! $category) {
            return null;
        }

        $exactMapping = static::query()
            ->with(['debitAccount', 'creditAccount'])
            ->where('financial_category_id', $category->getKey())
            ->where('transaction_type', $category->transaction_type)
            ->first();

        if ($exactMapping) {
            return $exactMapping->isUsable() ? $exactMapping : null;
        }

        if (blank($category->parent_category_id)) {
            return null;
        }

        $parentMapping = static::query()
            ->with(['debitAccount', 'creditAccount'])
            ->where('financial_parent_category_id', $category->parent_category_id)
            ->where('transaction_type', $category->transaction_type)
            ->first();

        return $parentMapping?->isUsable() ? $parentMapping : null;
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->transaction_type === FinancialCategory::TYPE_INCOME) {
            return $this->creditAccount?->is_active
                && in_array($this->creditAccount->type, [
                    ChartOfAccount::TYPE_REVENUE,
                    ChartOfAccount::TYPE_ASSET,
                    ChartOfAccount::TYPE_LIABILITY,
                    ChartOfAccount::TYPE_EQUITY,
                ], true)
                && (! $this->debitAccount || ($this->debitAccount->is_active && $this->debitAccount->type === ChartOfAccount::TYPE_ASSET));
        }

        return $this->debitAccount?->is_active
            && in_array($this->debitAccount->type, [
                ChartOfAccount::TYPE_EXPENSE,
                ChartOfAccount::TYPE_COGS,
                ChartOfAccount::TYPE_LIABILITY,
                ChartOfAccount::TYPE_ASSET,
                ChartOfAccount::TYPE_EQUITY,
            ], true)
            && (! $this->creditAccount || ($this->creditAccount->is_active && in_array($this->creditAccount->type, [ChartOfAccount::TYPE_ASSET, ChartOfAccount::TYPE_LIABILITY], true)));
    }

    public function getTargetNameAttribute(): string
    {
        return $this->financialCategory?->name
            ?? $this->financialParentCategory?->name
            ?? '-';
    }

    public function getTargetLevelAttribute(): string
    {
        return $this->financial_category_id ? 'Sub Kategori' : 'Kategori Utama';
    }

    public function getTypeLabelAttribute(): string
    {
        return FinancialCategory::typeOptions()[$this->transaction_type] ?? $this->transaction_type;
    }

    public function getDebitLabelAttribute(): string
    {
        return $this->debitAccount
            ? $this->debitAccount->code.' - '.$this->debitAccount->name
            : '-';
    }

    public function getCreditLabelAttribute(): string
    {
        return $this->creditAccount
            ? $this->creditAccount->code.' - '.$this->creditAccount->name
            : '-';
    }
}
