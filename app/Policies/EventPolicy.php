<?php

namespace App\Policies;

use App\Models\Karyawan;
use App\Models\Event;
use Illuminate\Auth\Access\HandlesAuthorization;

class EventPolicy
{
    use HandlesAuthorization;

    private function check(Karyawan $karyawan): bool
    {
        return $karyawan->role_id === 'R01' || 
            $karyawan->role_id === 'R02' || 
            $karyawan->role_id === 'R03' || 
            $karyawan->role_id === 'R06' || 
            $karyawan->hasRole(['Admin', 'admin', 'CEO', 'ceo', 'Manager HRD', 'manager hrd', 'Staff HRD', 'staff hrd']);
    }

    public function viewAny(Karyawan $karyawan): bool
    {
        return $this->check($karyawan);
    }

    public function view(Karyawan $karyawan, Event $event): bool
    {
        return $this->check($karyawan);
    }

    public function create(Karyawan $karyawan): bool
    {
        return $this->check($karyawan);
    }

    public function update(Karyawan $karyawan, Event $event): bool
    {
        return $this->check($karyawan);
    }

    public function delete(Karyawan $karyawan, Event $event): bool
    {
        return $this->check($karyawan);
    }

    public function deleteAny(Karyawan $karyawan): bool
    {
        return $this->check($karyawan);
    }
}
