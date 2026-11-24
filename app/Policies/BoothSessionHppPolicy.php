<?php

namespace App\Policies;

use App\Models\BoothSessionHpp;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class BoothSessionHppPolicy
{
    use HandlesAuthorization;

    public function before(Karyawan $user, string $ability): ?bool
    {
        if ($user->hasRole(['super_admin', 'Super Admin'])) {
            return true;
        }

        return null;
    }

    public function viewAny(Karyawan $user): bool
    {
        return $user->hasAnyPermission(['view_booth_hpp', 'manage_booth_hpp']);
    }

    public function view(Karyawan $user, BoothSessionHpp $record): bool
    {
        return $user->hasAnyPermission(['view_booth_hpp', 'manage_booth_hpp']);
    }

    public function updateTariff(Karyawan $user, BoothSessionHpp $record): bool
    {
        if ($record->isPosted()) {
            return false;
        }

        return $user->hasPermissionTo('manage_booth_hpp');
    }

    public function confirm(Karyawan $user, BoothSessionHpp $record): bool
    {
        if ($record->hpp_rate_per_sheet === null) {
            return false;
        }

        return $user->hasAnyPermission(['confirm_booth_hpp', 'manage_booth_hpp']);
    }

    public function reopen(Karyawan $user, BoothSessionHpp $record): bool
    {
        return $user->hasAnyPermission(['reopen_booth_hpp', 'manage_booth_hpp']);
    }

    public function post(Karyawan $user, BoothSessionHpp $record): bool
    {
        if (! $record->isConfirmed()) {
            return false;
        }

        return $user->hasAnyPermission(['post_booth_hpp', 'manage_booth_hpp']);
    }
}
