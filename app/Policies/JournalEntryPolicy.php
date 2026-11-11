<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\Karyawan;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('view_any_journal_entry');
    }

    public function view(Karyawan $user, JournalEntry $entry): bool
    {
        return $user->isSuperAdmin()
            || $user->can('view_journal_entry')
            || $user->can('view_any_journal_entry');
    }

    public function create(Karyawan $user): bool
    {
        return $user->isSuperAdmin() || $user->can('create_journal_entry');
    }

    public function update(Karyawan $user, JournalEntry $entry): bool
    {
        if ($entry->isPosted()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('update_journal_entry');
    }

    public function delete(Karyawan $user, JournalEntry $entry): bool
    {
        if ($entry->isPosted()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('delete_journal_entry');
    }

    public function post(Karyawan $user, JournalEntry $entry): bool
    {
        if (! $entry->isDraft()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('post_journal_entry');
    }

    public function reverse(Karyawan $user, JournalEntry $entry): bool
    {
        if (! $entry->isPosted()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->can('reverse_journal_entry');
    }
}
