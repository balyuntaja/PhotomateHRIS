<?php

namespace App\Policies;

use App\Models\CategoryAccountMapping;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class CategoryAccountMappingPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_category_account_mapping');
    }

    public function view(Karyawan $user, CategoryAccountMapping $mapping): bool
    {
        return $user->isSuperAdmin()
            || $user->can('view_category_account_mapping')
            || $user->can('view_any_category_account_mapping');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_category_account_mapping');
    }

    public function update(Karyawan $user, CategoryAccountMapping $mapping): bool
    {
        return $user->isSuperAdmin() || $user->can('update_category_account_mapping');
    }

    public function delete(Karyawan $user, CategoryAccountMapping $mapping): bool
    {
        return $user->isSuperAdmin() || $user->can('delete_category_account_mapping');
    }
}
