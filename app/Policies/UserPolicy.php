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

    /**
     * Pessoas e vínculos migraram para o Govnex Hub: a criação de membro
     * local do gabinete/entidade foi desligada (ver
     * docs/INTEGRACAO_GOVNEX_HUB.md). Root, gerido localmente, é exceção
     * fora deste fluxo (Admin/RootUserController).
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Papel e situação do vínculo também são geridos no Hub; a tela de
     * equipe do gabinete ficou somente leitura.
     */
    public function update(User $user, User $target): bool
    {
        return false;
    }

    public function delete(User $user, User $target): bool
    {
        return false;
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
