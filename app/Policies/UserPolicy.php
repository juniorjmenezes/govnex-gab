<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\OfficeContext;

class UserPolicy
{
    public function __construct(private readonly OfficeContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $this->context->roleFor($user)->canManageTeam();
    }

    public function view(User $user, User $target): bool
    {
        $officeId = $this->context->officeIdFor($user);

        return $user->isRoot()
            || ($officeId !== null && $officeId === $target->gabinete_id)
            || $user->belongsToSameGabineteAs($target);
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
