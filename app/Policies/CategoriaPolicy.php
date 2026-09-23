<?php

namespace App\Policies;

use App\Models\Categoria;
use App\Models\User;

class CategoriaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->gabinete_id !== null;
    }

    public function view(User $user, Categoria $categoria): bool
    {
        return $user->gabinete_id === $categoria->gabinete_id;
    }

    public function create(User $user): bool
    {
        return $user->role->isAdministrator();
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $this->view($user, $categoria) && $this->create($user);
    }

    public function delete(User $user, Categoria $categoria): bool
    {
        return $this->update($user, $categoria);
    }
}
