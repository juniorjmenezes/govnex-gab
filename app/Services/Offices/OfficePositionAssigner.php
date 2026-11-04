<?php

namespace App\Services\Offices;

use App\Enums\OfficePosition;
use App\Models\AccessContextEvent;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava o cargo local de um integrante e a trilha da mudança — o único ponto
 * de escrita de `gabinete_membros.cargo`, usado pela Equipe do gabinete
 * (`CARGO_ALTERADO`), pela Administração → Gabinetes do root e pelo comando
 * `gab:cargo-conceder` (`CARGO_CONCEDIDO_POR_OPERADOR`).
 *
 * Quem pode e quais cargos cabem no tipo do gabinete são decididos antes, por
 * `OfficeAccess` e pelos Form Requests/comando; aqui só se grava. Idempotente:
 * cargo igual não grava nem audita.
 */
class OfficePositionAssigner
{
    public const EVENT_TEAM = 'CARGO_ALTERADO';

    public const EVENT_OPERATOR = 'CARGO_CONCEDIDO_POR_OPERADOR';

    /**
     * Vínculo do integrante com o gabinete (ativo ou não); `null` para quem
     * não pertence a ele ou é root.
     */
    public function membershipOf(Office $office, User $member): ?OfficeMember
    {
        if ($member->isRoot()) {
            return null;
        }

        return OfficeMember::query()
            ->where('gabinete_id', $office->id)
            ->where('usuario_id', $member->id)
            ->first();
    }

    /**
     * Regras de quem grava (todas no backend, dentro da transação com lock
     * sobre os membros do gabinete):
     *
     * - no máximo **1 vereador e 1 chefe de gabinete ativos** por gabinete
     *   (membro inativo com o cargo não conta). `$replace` (só para a
     *   plataforma) retira o cargo de quem o tem hoje na mesma operação;
     * - fora da plataforma (`$actor` não-root; root ou `null` = comando de
     *   operador são plataforma): ninguém mexe no próprio cargo, só a
     *   plataforma mexe no cargo de vereador, e ninguém deixa o gabinete sem
     *   nenhum vereador/chefe ativo.
     *
     * @param  array<string, mixed>  $context  campos extras do `contexto` auditado
     * @return bool se houve mudança
     *
     * @throws ValidationException (`cargo`) quando a regra recusa
     */
    public function assign(
        OfficeMember $membership,
        ?OfficePosition $position,
        string $event,
        ?User $actor,
        array $context = [],
        ?Request $request = null,
        bool $replace = false,
    ): bool {
        if ($membership->cargo === $position) {
            return false;
        }

        $office = Office::withoutGlobalScopes()->findOrFail($membership->gabinete_id);
        $platform = $actor === null || $actor->isRoot();
        $key = (string) config('app.key');

        if (! $platform) {
            $this->guardTeamRules($membership, $position, $actor);
        }

        return DB::transaction(function () use ($membership, $position, $office, $event, $actor, $context, $request, $key, $platform, $replace): bool {
            /** @var Collection<int, OfficeMember> $members */
            $members = OfficeMember::query()
                ->where('gabinete_id', $office->id)
                ->with('usuario:id,name')
                ->lockForUpdate()
                ->get();

            /** @var OfficeMember $current */
            $current = $members->firstWhere('id', $membership->id) ?? $membership;
            $previous = $current->cargo;

            if ($previous === $position) {
                return false;
            }

            $replaced = null;

            if ($position?->leadsOffice() === true && $current->ativo) {
                $holder = $members->first(fn (OfficeMember $m): bool => $m->id !== $current->id && $m->ativo && $m->cargo === $position);

                if ($holder !== null && ! ($replace && $platform)) {
                    throw ValidationException::withMessages(['cargo' => self::occupiedMessage($position, $holder)]);
                }

                $replaced = $holder;
            }

            if (! $platform && $previous?->leadsOffice() === true && $current->ativo
                && ! $members->contains(fn (OfficeMember $m): bool => $m->id !== $current->id && $m->ativo && $m->cargo?->leadsOffice() === true)
                && $position?->leadsOffice() !== true) {
                throw ValidationException::withMessages(['cargo' => 'O gabinete não pode ficar sem vereador nem chefe de gabinete ativos. Atribua outro antes de mudar este cargo.']);
            }

            if ($replaced !== null) {
                $replaced->forceFill(['cargo' => null])->save();
            }

            $current->forceFill(['cargo' => $position])->save();
            $membership->setRawAttributes($current->getAttributes(), true);

            AccessContextEvent::query()->create([
                'entidade_id' => $office->entidade_id,
                'gabinete_id' => $office->id,
                'usuario_id' => $actor?->id,
                'evento' => $event,
                'resultado' => 'PERMITIDO',
                'rota' => $request?->route()?->getName(),
                'ip_hash' => $request?->ip() ? hash_hmac('sha256', (string) $request->ip(), $key) : null,
                'user_agent_hash' => $request?->userAgent() ? hash_hmac('sha256', (string) $request->userAgent(), $key) : null,
                'administrador_plataforma' => $actor?->isRoot() ?? false,
                'contexto' => [
                    'membro_id' => $current->usuario_id,
                    'de' => $previous?->value,
                    'para' => $position?->value,
                    ...$context,
                    ...($replaced !== null ? ['substituido_id' => $replaced->usuario_id] : []),
                ],
                'ocorrido_em' => now(),
            ]);

            return true;
        });
    }

    /** Regras da Equipe do gabinete (quem não é a plataforma). */
    private function guardTeamRules(OfficeMember $membership, ?OfficePosition $position, User $actor): void
    {
        if ($membership->usuario_id === $actor->id) {
            throw ValidationException::withMessages(['cargo' => 'Você não pode alterar o seu próprio cargo. Peça a outro responsável do gabinete ou à equipe GOVNEX.']);
        }

        if ($membership->cargo === OfficePosition::Councilor || $position === OfficePosition::Councilor) {
            throw ValidationException::withMessages(['cargo' => 'Só a equipe GOVNEX (root) atribui, altera ou remove o cargo de vereador.']);
        }
    }

    public static function occupiedMessage(OfficePosition $position, OfficeMember $holder): string
    {
        $name = $holder->usuario->name ?? 'outra pessoa';

        return $position === OfficePosition::Councilor
            ? "Este gabinete já tem um vereador ativo ({$name}). Remova ou altere o cargo dele antes."
            : "Este gabinete já tem um chefe de gabinete ativo ({$name}). Remova ou altere o cargo dele antes.";
    }
}
