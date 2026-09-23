<?php

namespace App\Policies;

use App\Models\Bairro;
use App\Models\User;

class BairroPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Bairro $bairro): bool
    {
        return $user->gabinete_id === $bairro->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->role->isAdministrator();
    }

    public function update(User $user, Bairro $bairro): bool
    {
        return $this->view($user, $bairro) && $this->create($user);
    }

    public function delete(User $user, Bairro $bairro): bool
    {
        return $this->update($user, $bairro);
    }
}
