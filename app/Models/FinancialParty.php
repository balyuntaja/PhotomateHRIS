<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialParty extends Model
{
    use HasFactory;

    public const TYPE_EMPLOYEE = 'employee';

    public const TYPE_CREW = 'crew';

    public const TYPE_COMPANY = 'company';

    public const TYPE_VENDOR = 'vendor';

    public const TYPE_OTHER = 'other';

    public const TYPE_LABELS = [
        self::TYPE_EMPLOYEE => 'Karyawan',
        self::TYPE_CREW => 'Crew',
        self::TYPE_COMPANY => 'Perusahaan',
        self::TYPE_VENDOR => 'Vendor / Supplier',
        self::TYPE_OTHER => 'Lain-lain',
    ];

    protected $table = 'financial_parties';

    protected $fillable = [
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
