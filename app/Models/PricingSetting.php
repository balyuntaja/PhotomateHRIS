<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    use HasFactory;

    protected $table = 'pricing_settings';

    protected $fillable = [
        'name',
        'base_price',
        'additional_session_price',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'integer',
        'additional_session_price' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    public static function getActiveForDate(?Carbon $date = null): ?self
    {
        $date = $date ? $date->copy()->startOfDay() : now()->startOfDay();

        return static::where('is_active', true)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
