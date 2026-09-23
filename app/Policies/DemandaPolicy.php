<?php

namespace App\Policies;

use App\Models\Demanda;
use App\Models\User;

class DemandaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Demanda $demanda): bool
    {
        return $user->gabinete_id === $demanda->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->gabinete_id !== null && $user->role->canWrite();
    }

    public function update(User $user, Demanda $demanda): bool
    {
        return $this->view($user, $demanda) && $user->role->canWrite();
    }

    public function delete(User $user, Demanda $demanda): bool
    {
        return $this->view($user, $demanda)
            && $user->role->isAdministrator();
    }
}
