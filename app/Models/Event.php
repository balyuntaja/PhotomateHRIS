<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
    ];

    public function queueEntries()
    {
        return $this->hasMany(QueueEntry::class);
    }
}
