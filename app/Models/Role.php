<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends SpatieRole
{
    protected $table = 'roles';
    protected $primaryKey = 'role_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'role_id',
        'name',
        'guard_name',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->role_id)) {
                // Generate a unique role_id
                // Format: R01, R02, etc.
                $maxId = static::where('role_id', 'like', 'R%')
                    ->orderByRaw('CAST(SUBSTRING(role_id, 2) AS UNSIGNED) DESC')
                    ->first()?->role_id;

                $number = 0;
                if ($maxId) {
                    $number = (int) substr($maxId, 1);
                }
                
                $nextId = 'R' . str_pad($number + 1, 2, '0', STR_PAD_LEFT);

                // Double check if nextId exists to avoid collisions
                while (static::where('role_id', $nextId)->exists()) {
                    $number++;
                    $nextId = 'R' . str_pad($number + 1, 2, '0', STR_PAD_LEFT);
                }

                $model->role_id = $nextId;
            }
        });
    }

    public function getKeyName()
    {
        return 'role_id';
    }

    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Override method untuk relasi dengan permissions
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            config('permission.models.permission'),
            config('permission.table_names.role_has_permissions'),
            config('permission.column_names.role_pivot_key'),
            config('permission.column_names.permission_pivot_key')
        );
    }

    /**
     * Override method untuk relasi dengan users
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            getModelForGuard($this->attributes['guard_name'] ?? config('auth.defaults.guard')),
            'model',
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.role_pivot_key'),
            config('permission.column_names.model_morph_key')
        );
    }

    /**
     * Relasi ke Karyawan
     */
    public function karyawan(): HasMany
    {
        return $this->hasMany(Karyawan::class, 'role_id', 'role_id');
    }
}