<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialParentCategory extends Model
{
    use HasFactory;

    public const NAME_INCOME = 'Pendapatan Booth';

    protected $table = 'financial_parent_categories';

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function subcategories(): HasMany
    {
        return $this->hasMany(FinancialCategory::class, 'parent_category_id');
    }
}
