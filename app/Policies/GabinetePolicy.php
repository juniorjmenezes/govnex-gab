<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Gabinete;
use App\Models\User;

class GabinetePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isRoot()
            || in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true);
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
            || ($user->role === UserRole::Councilor && $user->gabinete_id === $gabinete->id);
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
