<?php

namespace App\Policies;

use App\Models\ExpressEvent;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpressEventPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_express_events');
    }

    public function view(Karyawan $user, ExpressEvent $event): bool
    {
        return $user->isSuperAdmin() || $user->can('view_express_events');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_express_events');
    }

    public function update(Karyawan $user, ExpressEvent $event): bool
    {
        if ($event->isFinalized()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('update_express_events');
    }

    public function delete(Karyawan $user, ExpressEvent $event): bool
    {
        if ($event->isConfirmed() || $event->isFinalized()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('delete_express_events');
    }

    public function allocate(Karyawan $user, ExpressEvent $event): bool
    {
        if ($event->isFinalized()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('allocate_express_recaps');
    }

    public function manageHpp(Karyawan $user, ExpressEvent $event): bool
    {
        return $user->isSuperAdmin() || $user->can('manage_express_hpp');
    }

    public function confirmHpp(Karyawan $user, ExpressEvent $event): bool
    {
        return $user->isSuperAdmin() || $user->can('confirm_express_hpp');
    }

    public function finalize(Karyawan $user, ExpressEvent $event): bool
    {
        return $user->isSuperAdmin() || $user->can('finalize_express_events');
    }

    public function reopen(Karyawan $user, ExpressEvent $event): bool
    {
        return $user->isSuperAdmin() || $user->can('reopen_express_events');
    }
}
