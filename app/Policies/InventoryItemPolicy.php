<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\Karyawan;
use App\Utils\InventoryAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function view(Karyawan $user, InventoryItem $item): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function create(Karyawan $user): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function update(Karyawan $user, InventoryItem $item): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function delete(Karyawan $user, InventoryItem $item): bool
    {
        return false;
    }

    public function deleteAny(Karyawan $user): bool
    {
        return false;
    }

    public function restore(Karyawan $user, InventoryItem $item): bool
    {
        return false;
    }

    public function forceDelete(Karyawan $user, InventoryItem $item): bool
    {
        return false;
    }
}
