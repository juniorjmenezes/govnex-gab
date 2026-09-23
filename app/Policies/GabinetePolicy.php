<?php

namespace App\Policies;

use App\Models\Gabinete;
use App\Models\User;

class GabinetePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isRoot()
            || $user->role->isAdministrator();
    }

    public function view(User $user, Gabinete $gabinete): bool
    {
        return $user->isRoot() || $user->gabinete_id === $gabinete->id;
    }

    public function create(User $user): bool
    {
        return $user->isRoot();
    }

    public function update(User $user, Gabinete $gabinete): bool
    {
        return $user->isRoot()
            || ($user->role->isAdministrator() && $user->gabinete_id === $gabinete->id);
    }

    public function suspend(User $user, Gabinete $gabinete): bool
    {
        return $user->isRoot();
    }

    public function restore(User $user, Gabinete $gabinete): bool
    {
        return $user->isRoot();
    }

    public function forceDelete(User $user, Gabinete $gabinete): bool
    {
        return false;
    }
}
