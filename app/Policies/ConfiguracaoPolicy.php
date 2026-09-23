<?php

namespace App\Policies;

use App\Models\Configuracao;
use App\Models\User;

class ConfiguracaoPolicy
{
    public function view(User $user, Configuracao $configuracao): bool
    {
        return $user->gabinete_id === $configuracao->gabinete_id;
    }

    public function update(User $user, Configuracao $configuracao): bool
    {
        return $this->view($user, $configuracao) && $user->role->isAdministrator();
    }
}
