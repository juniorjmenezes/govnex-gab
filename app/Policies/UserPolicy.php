<?php

namespace App\Policies;

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

        // Administrador gerencia operadores, auditores e os demais
        // administradores do gabinete; root é conta local e nunca é gerida aqui.
        return $user->role->isAdministrator() && ! $target->isRoot();
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
