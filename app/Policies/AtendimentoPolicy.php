<?php

namespace App\Policies;

use App\Enums\UserRole;
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
        return $user->gabinete_id !== null;
    }

    public function update(User $user, Atendimento $atendimento): bool
    {
        return $this->view($user, $atendimento);
    }

    public function delete(User $user, Atendimento $atendimento): bool
    {
        return $this->view($user, $atendimento)
            && in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true);
    }
}
