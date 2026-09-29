<?php

namespace App\Policies;

use App\Models\FinancialCategory;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class FinancialCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_keuangan_category');
    }

    public function view(Karyawan $user, FinancialCategory $category): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_keuangan_category');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_keuangan_category');
    }

    public function update(Karyawan $user, FinancialCategory $category): bool
    {
        return $user->isSuperAdmin() || $user->can('update_keuangan_category');
    }

    public function delete(Karyawan $user, FinancialCategory $category): bool
    {
        return $user->isSuperAdmin() || $user->can('delete_keuangan_category');
    }
}
