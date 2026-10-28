<?php

namespace App\Services\Demands;

use App\Enums\DemandEventType;
use App\Models\Demand;
use App\Models\DemandEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Grava um evento na timeline da demanda e atualiza `ultima_atividade_em`.
 * É o único ponto de escrita da timeline — todas as ações (atualização,
 * encaminhamento, retorno, mudanças de status/responsável/prioridade/prazo,
 * resolução, reabertura, encerramento) passam por aqui, o que garante que a
 * timeline continue sendo a fonte única de "o que aconteceu".
 */
class DemandTimelineRecorder
{
    /**
     * @param  array<string, mixed>|null  $dados
     * @param  array<string, mixed>  $extra  Colunas estruturadas extras do evento
     *                                       (destino, setor, referencia_externa, prazo_esperado,
     *                                       retorno_de_evento_id, retorno_recebido_em).
     */
    public function record(
        Demand $demand,
        ?User $user,
        DemandEventType $tipo,
        ?string $descricao = null,
        ?array $dados = null,
        array $extra = [],
    ): DemandEvent {
        $event = new DemandEvent;
        $event->forceFill([
            'gabinete_id' => $demand->gabinete_id,
            'demanda_id' => $demand->id,
            'usuario_id' => $user?->id,
            'tipo' => $tipo,
            'descricao' => $descricao,
            'dados' => $dados,
            ...$extra,
        ])->save();

        $demand->forceFill(['ultima_atividade_em' => Carbon::now()])->saveQuietly();

        return $event;
    }
}
