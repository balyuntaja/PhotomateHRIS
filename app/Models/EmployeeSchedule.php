<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon|null $shift_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class EmployeeSchedule extends Model
{
    protected $table = 'employee_schedules';

    protected $fillable = [
        'karyawan_id',
        'employee_name',
        'shift_date',
        'shift_type',
        'booth_location',
        'notes',
    ];

    protected $casts = [
        'shift_date' => 'date',
    ];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id', 'karyawan_id');
    }

    protected static function booted()
    {
        static::saving(function ($schedule) {
            if ($schedule->karyawan_id && ($schedule->isDirty('karyawan_id') || !$schedule->employee_name)) {
                $schedule->employee_name = Karyawan::find($schedule->karyawan_id)?->nama_lengkap ?? '';
            }
        });
    }
}
