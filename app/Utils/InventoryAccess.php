<?php

namespace App\Utils;

use App\Models\Karyawan;

class InventoryAccess
{
    public static function allowed(?Karyawan $user): bool
    {
        if (! $user instanceof Karyawan) {
            return false;
        }

        if ($user->role_id === 'R01') {
            return true;
        }

        return in_array(
            strtolower((string) $user->role?->name),
            ['admin', 'super admin', 'super_admin', 'superadmin'],
            true,
        );
    }

    public static function authorize(): void
    {
        abort_unless(static::allowed(auth()->user()), 403, 'Anda tidak memiliki akses ke menu Inventory.');
    }
}
