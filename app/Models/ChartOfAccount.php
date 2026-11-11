<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_COGS = 'cogs';

    public const TYPE_EXPENSE = 'expense';

    public const NORMAL_DEBIT = 'debit';

    public const NORMAL_CREDIT = 'credit';

    public const CASH_FLOW_OPERATING = 'operating';

    public const CASH_FLOW_INVESTING = 'investing';

    public const CASH_FLOW_FINANCING = 'financing';

    public const CASH_FLOW_CASH = 'cash';

    public const CASH_FLOW_UNCLASSIFIED = 'unclassified';

    public const CASH_FLOW_LABELS = [
        self::CASH_FLOW_OPERATING => 'Operating',
        self::CASH_FLOW_INVESTING => 'Investing',
        self::CASH_FLOW_FINANCING => 'Financing',
        self::CASH_FLOW_CASH => 'Kas/Bank',
        self::CASH_FLOW_UNCLASSIFIED => 'Unclassified',
    ];

    public const TYPE_LABELS = [
        self::TYPE_ASSET => 'Aset',
        self::TYPE_LIABILITY => 'Liabilitas',
        self::TYPE_EQUITY => 'Ekuitas',
        self::TYPE_REVENUE => 'Pendapatan',
        self::TYPE_COGS => 'Harga Pokok Penjualan',
        self::TYPE_EXPENSE => 'Beban',
    ];

    public const REPORT_GROUP_LABELS = [
        'current_asset' => 'Aset Lancar',
        'fixed_asset' => 'Aset Tetap',
        'current_liability' => 'Liabilitas Jangka Pendek',
        'long_term_liability' => 'Liabilitas Jangka Panjang',
        'equity' => 'Ekuitas',
        'revenue' => 'Pendapatan',
        'hpp' => 'HPP',
        'opex' => 'OPEX',
        'other_income' => 'Pendapatan Lainnya',
        'other_expense' => 'Beban Lainnya',
    ];

    public const NORMAL_BALANCE_LABELS = [
        self::NORMAL_DEBIT => 'Debit',
        self::NORMAL_CREDIT => 'Kredit',
    ];

    private const TYPE_REPORT_GROUPS = [
        self::TYPE_ASSET => ['current_asset', 'fixed_asset'],
        self::TYPE_LIABILITY => ['current_liability', 'long_term_liability'],
        self::TYPE_EQUITY => ['equity'],
        self::TYPE_REVENUE => ['revenue', 'other_income'],
        self::TYPE_COGS => ['hpp'],
        self::TYPE_EXPENSE => ['opex', 'other_expense'],
    ];

    private const NORMAL_BALANCE_BY_TYPE = [
        self::TYPE_ASSET => self::NORMAL_DEBIT,
        self::TYPE_LIABILITY => self::NORMAL_CREDIT,
        self::TYPE_EQUITY => self::NORMAL_CREDIT,
        self::TYPE_REVENUE => self::NORMAL_CREDIT,
        self::TYPE_COGS => self::NORMAL_DEBIT,
        self::TYPE_EXPENSE => self::NORMAL_DEBIT,
    ];

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'parent_id',
        'report_group',
        'cash_flow_category',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->code = trim((string) $account->code);
            $account->name = trim((string) $account->name);

            if (! array_key_exists($account->type, self::TYPE_LABELS)) {
                throw new DomainException('Tipe akun tidak valid.');
            }

            $expectedNormalBalance = self::normalBalanceForType($account->type);

            if (blank($account->normal_balance)) {
                $account->normal_balance = $expectedNormalBalance;
            } elseif ($account->exists && $account->isDirty('type') && ! $account->isDirty('normal_balance')) {
                $account->normal_balance = $expectedNormalBalance;
            } elseif ($account->normal_balance !== $expectedNormalBalance) {
                throw new DomainException(
                    'Normal balance untuk tipe '.self::TYPE_LABELS[$account->type].' harus '.$expectedNormalBalance.'.'
                );
            }

            $allowedReportGroups = self::reportGroupsForType($account->type);

            if (blank($account->report_group)) {
                $account->report_group = $allowedReportGroups[0] ?? null;
            } elseif ($account->exists && $account->isDirty('type') && ! $account->isDirty('report_group')) {
                $account->report_group = $allowedReportGroups[0] ?? null;
            }

            if (! in_array($account->report_group, $allowedReportGroups, true)) {
                throw new DomainException('Report group tidak sesuai dengan tipe akun.');
            }

            if (filled($account->cash_flow_category)
                && ! array_key_exists($account->cash_flow_category, self::CASH_FLOW_LABELS)) {
                throw new DomainException('Klasifikasi cash flow tidak valid.');
            }

            $duplicateCode = static::query()
                ->where('code', $account->code)
                ->when($account->exists, fn (Builder $query): Builder => $query->whereKeyNot($account->getKey()))
                ->exists();

            if ($duplicateCode) {
                throw new DomainException('Kode akun sudah digunakan.');
            }

            if (filled($account->parent_id)) {
                if ($account->exists && (int) $account->parent_id === (int) $account->getKey()) {
                    throw new DomainException('Akun tidak dapat menjadi parent dirinya sendiri.');
                }

                $parent = static::query()->find($account->parent_id);

                if (! $parent) {
                    throw new DomainException('Akun parent tidak ditemukan.');
                }

                if ($parent->type !== $account->type) {
                    throw new DomainException('Akun parent harus memiliki tipe yang sama.');
                }

                if ($account->exists && $parent->isDescendantOf($account)) {
                    throw new DomainException('Relasi parent-child akan membentuk siklus.');
                }
            }
        });

        static::deleting(function (self $account): void {
            if (! $account->isDeletable()) {
                throw new DomainException(
                    'Akun tidak dapat dihapus karena masih memiliki subakun, mapping, saldo, atau baris jurnal.'
                );
            }
        });
    }

    public static function typeOptions(): array
    {
        return self::TYPE_LABELS;
    }

    public static function reportGroupOptions(?string $type = null): array
    {
        if ($type === null) {
            return self::REPORT_GROUP_LABELS;
        }

        return collect(self::reportGroupsForType($type))
            ->mapWithKeys(fn (string $key): array => [$key => self::REPORT_GROUP_LABELS[$key]])
            ->all();
    }

    public static function reportGroupsForType(string $type): array
    {
        return self::TYPE_REPORT_GROUPS[$type] ?? [];
    }

    public static function normalBalanceForType(string $type): ?string
    {
        return self::NORMAL_BALANCE_BY_TYPE[$type] ?? null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class, 'chart_of_account_id');
    }

    public function debitMappings(): HasMany
    {
        return $this->hasMany(CategoryAccountMapping::class, 'debit_account_id');
    }

    public function creditMappings(): HasMany
    {
        return $this->hasMany(CategoryAccountMapping::class, 'credit_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'chart_of_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isDescendantOf(self $ancestor): bool
    {
        $current = $this->parent;
        $guard = 0;

        while ($current) {
            if ((int) $current->getKey() === (int) $ancestor->getKey()) {
                return true;
            }

            $current = $current->parent;

            if (++$guard > 50) {
                return false;
            }
        }

        return false;
    }

    public function isDeletable(): bool
    {
        return ! $this->children()->exists()
            && ! $this->financialAccounts()->exists()
            && ! $this->debitMappings()->exists()
            && ! $this->creditMappings()->exists()
            && ! $this->journalLines()->exists();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getReportGroupLabelAttribute(): string
    {
        return self::REPORT_GROUP_LABELS[$this->report_group] ?? $this->report_group;
    }

    public function getNormalBalanceLabelAttribute(): string
    {
        return self::NORMAL_BALANCE_LABELS[$this->normal_balance] ?? $this->normal_balance;
    }

    public function getDepthAttribute(): int
    {
        $depth = 0;
        $current = $this->parent;
        $guard = 0;

        while ($current) {
            $depth++;
            $current = $current->parent;

            if (++$guard > 50) {
                break;
            }
        }

        return $depth;
    }

    public function getIndentedNameAttribute(): string
    {
        return str_repeat('— ', $this->depth).$this->name;
    }
}
