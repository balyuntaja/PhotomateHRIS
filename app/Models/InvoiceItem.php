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
                return $parts[0] ?? null;
            },
            set: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $end = $parts[1] ?? '';
                return ['time_range' => trim($value . ' - ' . $end, ' -')];
            }
        );
    }

    protected function endTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                return $parts[1] ?? null;
            },
            set: function ($value, $attributes) {
                $parts = explode(' - ', $attributes['time_range'] ?? '');
                $start = $parts[0] ?? '';
                return ['time_range' => trim($start . ' - ' . $value, ' -')];
            }
        );
    }
}
