<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'marketing_consent' => 'boolean',
    ];

    public function queueEntries()
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function setWhatsappAttribute($value)
    {
        $this->attributes['whatsapp'] = static::normalizeWhatsapp($value);
    }

    public static function normalizeWhatsapp($number): string
    {
        $cleaned = preg_replace('/\D/', '', $number);

        if (str_starts_with($cleaned, '0')) {
            $cleaned = '62' . substr($cleaned, 1);
        }

        return $cleaned;
    }
}
