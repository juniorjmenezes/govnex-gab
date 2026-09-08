<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Cidadao;
use App\Models\User;

class CidadaoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Cidadao $cidadao): bool
    {
        return $user->gabinete_id === $cidadao->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function update(User $user, Cidadao $cidadao): bool
    {
        return $this->view($user, $cidadao);
    }

    public function delete(User $user, Cidadao $cidadao): bool
    {
        return $this->view($user, $cidadao)
            && in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true);
    }
}
