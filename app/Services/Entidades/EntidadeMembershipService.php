<?php

namespace App\Services\Entidades;

use App\Enums\EntidadeRole;
use App\Enums\EntidadeType;
use App\Enums\GabineteRole;
use App\Enums\GabineteType;
use App\Enums\UserRole;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteLideranca;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EntidadeMembershipService
{
    public function syncLegacyUser(User $user, ?User $actor = null): void
    {
        if ($user->gabinete_id === null || $user->isRoot()) {
            return;
        }

        $gabinete = Gabinete::withoutGlobalScopes()->find($user->gabinete_id);
        if ($gabinete === null) {
            return;
        }

        $entidade = Entidade::query()->find($gabinete->entidade_id);
        if ($entidade === null) {
            return;
        }

        [$entidadeRole, $gabineteRole] = match ($user->role) {
            UserRole::Councilor => [
                $entidade->tipo === EntidadeType::IndependentOffice
                    ? EntidadeRole::Administrator
                    : EntidadeRole::Operator,
                GabineteRole::Leader,
            ],
            UserRole::ChiefOfStaff => [EntidadeRole::Manager, GabineteRole::Manager],
            UserRole::Advisor => [EntidadeRole::Operator, GabineteRole::Member],
            default => [EntidadeRole::Operator, GabineteRole::Member],
        };

        DB::transaction(function () use ($user, $gabinete, $entidade, $entidadeRole, $gabineteRole, $actor): void {
            $entidadeMembership = EntidadeMembro::query()->firstOrNew([
                'entidade_id' => $gabinete->entidade_id,
                'usuario_id' => $user->id,
            ]);
            $preserveContextualRole = $entidadeMembership->exists
                && $entidade->tipo !== EntidadeType::IndependentOffice;
            $entidadeMembership->forceFill([
                ...($preserveContextualRole ? [] : ['papel' => $entidadeRole]),
                'ativo' => $user->is_active,
                'ingressou_em' => $entidadeMembership->ingressou_em ?? $user->created_at ?? now(),
                'desativado_em' => $user->is_active ? null : now(),
                'criado_por' => $entidadeMembership->criado_por ?? $actor?->id,
            ])->save();

            GabineteMembro::query()->updateOrCreate(
                ['gabinete_id' => $gabinete->id, 'usuario_id' => $user->id],
                [
                    'papel' => $gabineteRole,
                    'ativo' => $user->is_active,
                    'ingressou_em' => $user->created_at ?? now(),
                    'desativado_em' => $user->is_active ? null : now(),
                    'criado_por' => $actor?->id,
                ],
            );

            if ($gabineteRole === GabineteRole::Leader && $user->is_active) {
                GabineteLideranca::query()
                    ->where('gabinete_id', $gabinete->id)
                    ->whereNull('fim_em')
                    ->where('usuario_id', '!=', $user->id)
                    ->update(['fim_em' => now()->subDay()->toDateString()]);

                GabineteLideranca::query()->firstOrCreate(
                    [
                        'gabinete_id' => $gabinete->id,
                        'usuario_id' => $user->id,
                        'fim_em' => null,
                    ],
                    [
                        'entidade_id' => $gabinete->entidade_id,
                        'nome_snapshot' => $user->name,
                        'rotulo' => ($gabinete->tipo_gabinete ?? GabineteType::IndependentOffice)->leaderLabel(),
                        'inicio_em' => now()->toDateString(),
                        'registrado_por' => $actor?->id,
                    ],
                );
            } else {
                GabineteLideranca::query()
                    ->where('gabinete_id', $gabinete->id)
                    ->where('usuario_id', $user->id)
                    ->whereNull('fim_em')
                    ->update(['fim_em' => now()->toDateString()]);
            }
        });
    }
}
