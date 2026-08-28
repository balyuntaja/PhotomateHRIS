<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrLink extends Model
{
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = static::generateUniqueSlug();
            } else {
                $model->slug = \Illuminate\Support\Str::slug($model->slug);
            }
        });

        static::updating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = static::generateUniqueSlug();
            } else {
                $model->slug = \Illuminate\Support\Str::slug($model->slug);
            }
        });
    }

    public static function generateUniqueSlug(): string
    {
        do {
            $slug = \Illuminate\Support\Str::random(6);
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }
}
