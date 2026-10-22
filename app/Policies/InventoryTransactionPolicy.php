<?php

namespace App\Policies;

use App\Models\InventoryTransaction;
use App\Models\Karyawan;
use App\Utils\InventoryAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function view(Karyawan $user, InventoryTransaction $transaction): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function create(Karyawan $user): bool
    {
        return InventoryAccess::allowed($user);
    }

    public function update(Karyawan $user, InventoryTransaction $transaction): bool
    {
        return false;
    }

    public function delete(Karyawan $user, InventoryTransaction $transaction): bool
    {
        return false;
    }

    public function deleteAny(Karyawan $user): bool
    {
        return false;
    }
}
