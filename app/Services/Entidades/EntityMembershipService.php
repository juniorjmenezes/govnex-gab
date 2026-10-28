<?php

namespace App\Services\Entidades;

use App\Enums\AccessRole;
use App\Enums\EntityType;
use App\Models\Entity;
use App\Models\EntityMember;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EntityMembershipService
{
    public function syncLegacyUser(User $user, ?User $actor = null): void
    {
        if ($user->gabinete_id === null || $user->isRoot()) {
            return;
        }

        $gabinete = Office::withoutGlobalScopes()->find($user->gabinete_id);
        if ($gabinete === null) {
            return;
        }

        $entidade = Entity::query()->find($gabinete->entidade_id);
        if ($entidade === null) {
            return;
        }

        // O papel da conta vale para o gabinete; na entidade, administrador só
        // continua administrador se ela for um gabinete independente.
        $gabineteRole = $user->role->accessRole() ?? AccessRole::Operator;
        $entidadeRole = $gabineteRole->forEntidadeOfUnit($entidade->tipo);

        DB::transaction(function () use ($user, $gabinete, $entidade, $entidadeRole, $gabineteRole, $actor): void {
            $entidadeMembership = EntityMember::query()->firstOrNew([
                'entidade_id' => $gabinete->entidade_id,
                'usuario_id' => $user->id,
            ]);
            $preserveContextualRole = $entidadeMembership->exists
                && $entidade->tipo !== EntityType::IndependentOffice;
            $entidadeMembership->forceFill([
                ...($preserveContextualRole ? [] : ['papel' => $entidadeRole]),
                'ativo' => $user->is_active,
                'ingressou_em' => $entidadeMembership->ingressou_em ?? $user->created_at ?? now(),
                'desativado_em' => $user->is_active ? null : now(),
                'criado_por' => $entidadeMembership->criado_por ?? $actor?->id,
            ])->save();

            OfficeMember::query()->updateOrCreate(
                ['gabinete_id' => $gabinete->id, 'usuario_id' => $user->id],
                [
                    'papel' => $gabineteRole,
                    'ativo' => $user->is_active,
                    'ingressou_em' => $user->created_at ?? now(),
                    'desativado_em' => $user->is_active ? null : now(),
                    'criado_por' => $actor?->id,
                ],
            );
        });
    }
}
