<?php

namespace App\Policies;

use App\Models\InventoryStock;
use App\Models\Karyawan;
use App\Utils\InventoryAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryStockPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function view(Karyawan $user, InventoryStock $stock): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function create(Karyawan $user): bool
    {
        return false;
    }

    public function update(Karyawan $user, InventoryStock $stock): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function delete(Karyawan $user, InventoryStock $stock): bool
    {
        return false;
    }

    public function deleteAny(Karyawan $user): bool
    {
        return false;
    }
}
