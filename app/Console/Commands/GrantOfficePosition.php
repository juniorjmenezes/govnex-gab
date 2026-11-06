<?php

namespace App\Console\Commands;

use App\Enums\OfficePosition;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use App\Services\Offices\OfficePositionAssigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Partida da administração de cargos (PLATFORM.md §3.1, padrão do
 * `gho:gestor-acesso-conceder`).
 *
 * O Hub não concede papéis: o primeiro cargo de administração de um gabinete
 * (uso esperado: VEREADOR ou CHEFE_GABINETE) só é dado por quem opera o
 * servidor, com motivo obrigatório e auditoria com ator = sistema. Depois da
 * partida, quem tem esses cargos atribui os demais pela Equipe. Aceita
 * qualquer cargo permitido ao tipo do gabinete (`OfficePosition::allowedFor()`).
 *
 * O gabinete é identificado pelo id da unidade no Hub (`gabinetes.hub_unidade_id`),
 * o mesmo sistema de identificadores da pessoa (`users.hub_user_id`).
 */
#[Signature('gab:cargo-conceder {hub_user_id : sub da pessoa no Hub} {hub_unidade_id : id da unidade (gabinete) no Hub} {cargo : VEREADOR, CHEFE_GABINETE, SECRETARIO, ASSESSOR ou AUDITOR} {--motivo= : justificativa registrada na auditoria}')]
#[Description('Concede o cargo local a uma pessoa num gabinete (partida da administração de cargos; procedimento controlado e auditado).')]
class GrantOfficePosition extends Command
{
    public function handle(OfficePositionAssigner $positions): int
    {
        $reason = trim((string) $this->option('motivo'));

        if ($reason === '') {
            $this->error('Informe --motivo="justificativa" (fica na auditoria).');

            return self::FAILURE;
        }

        $position = OfficePosition::tryFrom(strtoupper(trim((string) $this->argument('cargo'))));

        if ($position === null) {
            $this->error('Cargo inválido. Use um de: '.implode(', ', array_map(
                fn (OfficePosition $p): string => $p->value,
                OfficePosition::cases(),
            )).'.');

            return self::FAILURE;
        }

        $user = User::query()->where('hub_user_id', (string) $this->argument('hub_user_id'))->first();

        if ($user === null) {
            $this->error('Nenhuma pessoa com esse hub_user_id no GAB (ela precisa ter entrado pelo Hub ou ter sido espelhada).');

            return self::FAILURE;
        }

        if ($user->isRoot()) {
            $this->error('Root é conta local da plataforma e não recebe cargo.');

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error('A pessoa está inativa no GAB.');

            return self::FAILURE;
        }

        $office = Office::withoutGlobalScopes()
            ->where('hub_unidade_id', (string) $this->argument('hub_unidade_id'))
            ->first();

        if ($office === null) {
            $this->error('Nenhum gabinete ligado a essa unidade do Hub (rode hub:espelhar-estrutura).');

            return self::FAILURE;
        }

        if (! $position->isAllowedFor($office->tipo_gabinete)) {
            $allowed = OfficePosition::allowedFor($office->tipo_gabinete);
            $this->error(sprintf(
                'O cargo %s não é permitido em %s (%s). Permitidos: %s.',
                $position->value,
                $office->tipo_gabinete->label(),
                $office->nome,
                $allowed === [] ? 'nenhum' : implode(', ', array_map(fn (OfficePosition $p): string => $p->value, $allowed)),
            ));

            return self::FAILURE;
        }

        $membership = OfficeMember::query()
            ->where('gabinete_id', $office->id)
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->first();

        if ($membership === null) {
            $this->error('A pessoa não tem vínculo ativo com esse gabinete.');

            return self::FAILURE;
        }

        $previous = $membership->cargo;

        if ($previous === $position) {
            $this->info("{$user->name} já tem o cargo {$position->label()} em {$office->nome}. Nada alterado.");

            return self::SUCCESS;
        }

        try {
            $positions->assign($membership, $position, OfficePositionAssigner::EVENT_OPERATOR, null, [
                'origem' => 'gab:cargo-conceder',
                'ator' => 'sistema',
                'motivo' => $reason,
            ]);
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->info($previous === null
            ? "Cargo {$position->label()} concedido a {$user->name} em {$office->nome}."
            : "Cargo de {$user->name} em {$office->nome} alterado de {$previous->label()} para {$position->label()}.");

        return self::SUCCESS;
    }
}
