<?php

namespace App\Policies;

use App\Models\FinancialPaymentMethod;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class FinancialPaymentMethodPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_keuangan_payment_method');
    }

    public function view(Karyawan $user, FinancialPaymentMethod $paymentMethod): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_keuangan_payment_method');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_keuangan_payment_method');
    }

    public function update(Karyawan $user, FinancialPaymentMethod $paymentMethod): bool
    {
        return $user->isSuperAdmin() || $user->can('update_keuangan_payment_method');
    }

    public function delete(Karyawan $user, FinancialPaymentMethod $paymentMethod): bool
    {
        return $user->isSuperAdmin() || $user->can('delete_keuangan_payment_method');
    }
}
