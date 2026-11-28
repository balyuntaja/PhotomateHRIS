<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
    ];

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function sessionReports(): HasMany
    {
        return $this->hasMany(SessionReport::class, 'event_id');
    }

    public function expressEvents(): HasMany
    {
        return $this->hasMany(ExpressEvent::class, 'event_id');
    }
}
