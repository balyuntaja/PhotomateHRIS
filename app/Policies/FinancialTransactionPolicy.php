<?php

namespace App\Policies;

use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class FinancialTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_keuangan_transaction');
    }

    public function view(Karyawan $user, FinancialTransaction $transaction): bool
    {
        return $user->isSuperAdmin() || $user->can('view_keuangan_transaction');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_keuangan_transaction');
    }

    public function update(Karyawan $user, FinancialTransaction $transaction): bool
    {
        if ($user->isSuperAdmin() || $user->can('update_keuangan_transaction')) {
            return true;
        }

        return $user->can('update_own_keuangan_transaction')
            && $transaction->created_by === $user->karyawan_id;
    }

    public function delete(Karyawan $user, FinancialTransaction $transaction): bool
    {
        if ($user->isSuperAdmin() || $user->can('delete_keuangan_transaction')) {
            return true;
        }

        return $user->can('delete_own_keuangan_transaction')
            && $transaction->created_by === $user->karyawan_id;
    }

    public function deleteAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('delete_keuangan_transaction');
    }

    public function restore(Karyawan $user, FinancialTransaction $transaction): bool
    {
        return $user->isSuperAdmin() || $user->can('restore_keuangan_transaction');
    }

    public function forceDelete(Karyawan $user, FinancialTransaction $transaction): bool
    {
        return $user->isSuperAdmin() || $user->can('force_delete_keuangan_transaction');
    }
}
