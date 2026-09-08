<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->role->canManageTeam();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isRoot() || $user->belongsToSameGabineteAs($target);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->role->canManageTeam();
    }

    public function update(User $user, User $target): bool
    {
        if ($user->isRoot()) {
            return true;
        }

        if (! $user->belongsToSameGabineteAs($target)) {
            return false;
        }

        return match ($user->role) {
            UserRole::Councilor => true,
            UserRole::ChiefOfStaff => $target->role === UserRole::Advisor,
            default => false,
        };
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        return $user->isRoot() || $this->update($user, $target);
    }

    public function restore(User $user, User $target): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $target): bool
    {
        return false;
    }
}
