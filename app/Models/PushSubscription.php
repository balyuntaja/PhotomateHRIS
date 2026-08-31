<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $fillable = [
        'queue_entry_id',
        'event_id',
        'endpoint',
        'endpoint_sha256',
        'p256dh',
        'auth',
    ];

    public static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if ($model->endpoint) {
                $model->endpoint_sha256 = hash('sha256', $model->endpoint);
            }
        });
    }

    public function queueEntry()
    {
        return $this->belongsTo(QueueEntry::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
