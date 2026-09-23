<?php

namespace App\Policies;

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
        return $user->gabinete_id !== null && $user->role->canWrite();
    }

    public function update(User $user, Cidadao $cidadao): bool
    {
        return $this->view($user, $cidadao) && $user->role->canWrite();
    }

    public function delete(User $user, Cidadao $cidadao): bool
    {
        return $this->view($user, $cidadao)
            && $user->role->isAdministrator();
    }
}
