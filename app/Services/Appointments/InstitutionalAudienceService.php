<?php

namespace App\Services\Appointments;

use App\Enums\OfficeStatus;
use App\Enums\OfficeType;
use App\Models\ComissaoLegislativa;
use App\Models\ComissaoMembro;
use App\Models\CompromissoInstitucional;
use Illuminate\Validation\ValidationException;

/**
 * Público de um compromisso institucional (`compromisso_institucional_gabinetes`).
 *
 * Sem comissão, vale a lista escolhida à mão (vazia = todos os vereadores).
 * Com comissão, o público é um retrato dos membros ativos dela no momento em
 * que o vínculo é feito: mudanças posteriores na composição não alteram
 * compromissos já gravados.
 *
 * Na edição, manter a mesma comissão preserva o retrato gravado (salvar o
 * título ou o horário não reconvoca ninguém); trocar de comissão tira um
 * retrato novo; tirar a comissão volta para a lista manual.
 *
 * Deve rodar dentro da transação que grava o compromisso, depois de salvo:
 * as recusas abaixo desfazem a gravação inteira.
 */
class InstitutionalAudienceService
{
    /** @param  list<int>  $manualTargets */
    public function apply(CompromissoInstitucional $compromisso, ?int $comissaoId, array $manualTargets): void
    {
        if ($comissaoId === null) {
            $compromisso->forceFill(['comissao_id' => null])->save();
            $compromisso->gabinetesAlvo()->sync($manualTargets);

            return;
        }

        if ($compromisso->comissao_id === $comissaoId) {
            return;
        }

        // Travada como em `CommitteeMembershipService`: uma inclusão de membro
        // simultânea não entra pela metade no retrato.
        $comissao = ComissaoLegislativa::query()->whereKey($comissaoId)->lockForUpdate()->first();

        if ($comissao === null || (int) $comissao->entidade_id !== (int) $compromisso->entidade_id) {
            throw ValidationException::withMessages([
                'comissao_id' => 'Selecione uma comissão desta Câmara.',
            ]);
        }

        if (! $comissao->isActive()) {
            throw ValidationException::withMessages([
                'comissao_id' => 'Comissão extinta não recebe novas reuniões.',
            ]);
        }

        $targets = $this->activeMemberOffices($comissao);

        // Sem alvo, o compromisso iria para todos os vereadores — o oposto
        // do que se quer ao vinculá-lo a uma comissão.
        if ($targets === []) {
            throw ValidationException::withMessages([
                'comissao_id' => 'A comissão não tem membros ativos para convocar.',
            ]);
        }

        $compromisso->forceFill(['comissao_id' => $comissao->id])->save();
        $compromisso->gabinetesAlvo()->sync($targets);
    }

    /**
     * Gabinetes dos membros ativos que ainda podem ser alvo: os mesmos
     * critérios da lista manual (gabinete de vereador ativo da Câmara).
     *
     * @return list<int>
     */
    public function activeMemberOffices(ComissaoLegislativa $comissao): array
    {
        return $this->activeMemberOfficesByCommittee([$comissao->id], (int) $comissao->entidade_id)[$comissao->id] ?? [];
    }

    /**
     * Mesma regra de `activeMemberOffices()` para várias comissões numa
     * consulta só (opções do formulário).
     *
     * @param  array<int, int>  $comissaoIds
     * @return array<int, list<int>> gabinetes por id de comissão
     */
    public function activeMemberOfficesByCommittee(array $comissaoIds, int $entidadeId): array
    {
        if ($comissaoIds === []) {
            return [];
        }

        $offices = [];

        ComissaoMembro::query()
            ->active()
            ->whereIn('comissao_id', $comissaoIds)
            ->whereIn('gabinete_id', fn ($eligible) => $eligible
                ->select('id')
                ->from('gabinetes')
                ->where('entidade_id', $entidadeId)
                ->where('tipo_gabinete', OfficeType::CouncilorOffice->value)
                ->where('status', OfficeStatus::Active->value)
                ->whereNull('deleted_at'))
            ->orderBy('gabinete_id')
            ->get(['comissao_id', 'gabinete_id'])
            ->each(function (ComissaoMembro $membro) use (&$offices): void {
                $offices[(int) $membro->comissao_id][] = (int) $membro->gabinete_id;
            });

        return $offices;
    }
}
