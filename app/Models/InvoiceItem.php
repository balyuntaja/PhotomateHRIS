<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = [];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function startTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $val = $parts[0] ?? null;
                return $val === '-' ? null : $val;
            },
            set: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $end = !empty($parts[1]) && $parts[1] !== '-' ? $parts[1] : null;
                $start = $value ?: null;
                
                if ($start === null && $end === null) {
                    $range = '-';
                } else {
                    $range = ($start ?: '-') . ' - ' . ($end ?: '-');
                }
                return ['time_range' => $range];
            }
        );
    }

    protected function endTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $val = $parts[1] ?? null;
                return $val === '-' ? null : $val;
            },
            set: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $start = !empty($parts[0]) && $parts[0] !== '-' ? $parts[0] : null;
                $end = $value ?: null;
                
                if ($start === null && $end === null) {
                    $range = '-';
                } else {
                    $range = ($start ?: '-') . ' - ' . ($end ?: '-');
                }
                return ['time_range' => $range];
            }
        );
    }
}
