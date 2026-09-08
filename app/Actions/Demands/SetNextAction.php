<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Models\Demanda;
use App\Models\User;
use App\Services\Demands\DemandNotificationService;
use App\Services\Demands\DemandTimelineRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Próxima ação" é um único slot na demanda (não uma lista de tarefas):
 * descrição, data e responsável do próximo passo esperado. Definir uma nova
 * substitui a anterior — o histórico de próximas ações já definidas fica na
 * timeline, não numa tabela à parte.
 *
 * Só conta como uma nova definição (evento na timeline + notificação) se
 * não havia ação pendente antes desta chamada. Se já havia uma pendente,
 * esta chamada é tratada como edição dela (corrigir descrição/data/
 * responsável pelo lápis do painel) e não gera um novo evento nem
 * notificação repetida.
 */
class SetNextAction
{
    public function __construct(
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandNotificationService $notifications,
    ) {}

    public function handle(
        Demanda $demand,
        User $user,
        string $descricao,
        ?Carbon $data,
        ?User $responsavel,
    ): Demanda {
        return DB::transaction(function () use ($demand, $user, $descricao, $data, $responsavel): Demanda {
            // Reabrir o formulário com o lápis reenvia esta mesma rota para
            // corrigir descrição/data/responsável — sem essa checagem, cada
            // ajuste duplicava o evento "definida" na timeline e reenviava a
            // notificação de atribuição.
            $isEditingPendingAction = $demand->hasNextActionPending();

            $demand->forceFill([
                'proxima_acao_descricao' => $descricao,
                'proxima_acao_data' => $data,
                'proxima_acao_responsavel_id' => $responsavel !== null ? $responsavel->id : $user->id,
                'proxima_acao_concluida_em' => null,
            ])->save();

            if (! $isEditingPendingAction) {
                $this->timeline->record(
                    $demand,
                    $user,
                    DemandEventType::ProximaAcaoDefinida,
                    $descricao,
                    array_filter([
                        'data' => $data?->toDateString(),
                        'responsavel_id' => $demand->proxima_acao_responsavel_id,
                    ]),
                );

                $this->notifications->nextActionAssigned($demand, $user);
            }

            return $demand;
        });
    }
}
