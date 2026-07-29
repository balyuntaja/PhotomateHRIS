<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = [];

    protected ?bool $isTbaTemp = null;

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function isTba(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value, $attributes) {
                return ($attributes['time_range'] ?? '') === 'TBA';
            },
            set: function ($value) {
                $this->isTbaTemp = (bool) $value;
                if ($value) {
                    return ['time_range' => 'TBA'];
                }
                return [];
            }
        );
    }

    protected function startTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value, $attributes) {
                if (($attributes['time_range'] ?? '') === 'TBA') {
                    return null;
                }
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $val = $parts[0] ?? null;
                return $val === '-' ? null : $val;
            },
            set: function ($value, $attributes) {
                if ($this->isTbaTemp === true) {
                    return ['time_range' => 'TBA'];
                }
                if ($this->isTbaTemp === null && ($attributes['time_range'] ?? '') === 'TBA' && $value === null) {
                    return ['time_range' => 'TBA'];
                }
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
                if (($attributes['time_range'] ?? '') === 'TBA') {
                    return null;
                }
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $val = $parts[1] ?? null;
                return $val === '-' ? null : $val;
            },
            set: function ($value, $attributes) {
                if ($this->isTbaTemp === true) {
                    return ['time_range' => 'TBA'];
                }
                if ($this->isTbaTemp === null && ($attributes['time_range'] ?? '') === 'TBA' && $value === null) {
                    return ['time_range' => 'TBA'];
                }
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
