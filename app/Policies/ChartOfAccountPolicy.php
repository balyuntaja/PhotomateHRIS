<?php

namespace App\Policies;

use App\Models\ChartOfAccount;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChartOfAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_chart_of_account');
    }

    public function view(Karyawan $user, ChartOfAccount $account): bool
    {
        return $user->isSuperAdmin()
            || $user->can('view_chart_of_account')
            || $user->can('view_any_chart_of_account');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_chart_of_account');
    }

    public function update(Karyawan $user, ChartOfAccount $account): bool
    {
        return $user->isSuperAdmin() || $user->can('update_chart_of_account');
    }

    public function delete(Karyawan $user, ChartOfAccount $account): bool
    {
        return $user->isSuperAdmin() || $user->can('delete_chart_of_account');
    }
}
