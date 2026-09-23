<?php

namespace App\Policies;

use App\Models\Atendimento;
use App\Models\User;

class AtendimentoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Atendimento $atendimento): bool
    {
        return $user->gabinete_id === $atendimento->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->gabinete_id !== null && $user->role->canWrite();
    }

    public function update(User $user, Atendimento $atendimento): bool
    {
        return $this->view($user, $atendimento) && $user->role->canWrite();
    }

    public function delete(User $user, Atendimento $atendimento): bool
    {
        return $this->view($user, $atendimento)
            && $user->role->isAdministrator();
    }
}
