<?php

namespace App\Policies;

use App\Models\Evento;
use App\Models\User;

class EventoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Evento $evento): bool
    {
        return $user->gabinete_id === $evento->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->gabinete_id !== null && $user->role->canWrite();
    }

    public function update(User $user, Evento $evento): bool
    {
        return $this->view($user, $evento) && $user->role->canWrite();
    }

    public function delete(User $user, Evento $evento): bool
    {
        return $this->view($user, $evento)
            && $user->role->isAdministrator();
    }
}
