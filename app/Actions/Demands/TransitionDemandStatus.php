<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandResultado;
use App\Enums\DemandStatus;
use App\Models\Demanda;
use App\Models\User;
use App\Services\Demands\DemandNotificationService;
use App\Services\Demands\DemandTimelineRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Primitiva única de mudança de status, reaproveitada por toda a aplicação
 * (pílula de status na tela de detalhe, Kanban, e as ações dedicadas
 * ResolveDemand/CloseDemand/ReopenDemand) — não existe uma segunda cópia
 * dessa lógica em nenhum outro lugar.
 */
class TransitionDemandStatus
{
    public function __construct(
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $dados */
    public function handle(
        Demanda $demand,
        DemandStatus $status,
        User $user,
        DemandEventType $eventType = DemandEventType::StatusAlterado,
        ?string $descricao = null,
        array $dados = [],
        ?DemandResultado $resultado = null,
    ): Demanda {
        return DB::transaction(function () use ($demand, $status, $user, $eventType, $descricao, $dados, $resultado): Demanda {
            $locked = Demanda::query()->lockForUpdate()->findOrFail($demand->id);
            $previous = $locked->status;

            if ($previous === $status) {
                return $locked;
            }

            if (! $previous->canTransitionTo($status)) {
                throw new DomainException("A transição de {$previous->label()} para {$status->label()} não é permitida.");
            }

            $locked->forceFill([
                'status' => $status,
                'concluida_em' => match (true) {
                    $status === DemandStatus::Resolved => $locked->concluida_em ?? now(),
                    $status === DemandStatus::Closed => $locked->concluida_em,
                    default => null,
                },
                'encerrada_em' => $status === DemandStatus::Closed ? ($locked->encerrada_em ?? now()) : null,
                'resultado' => match (true) {
                    $status === DemandStatus::Resolved => $resultado ?? $locked->resultado,
                    $status === DemandStatus::Closed => $locked->resultado,
                    default => null,
                },
            ])->save();

            $this->timeline->record(
                $locked,
                $user,
                $eventType,
                $descricao ?? "Status alterado de {$previous->label()} para {$status->label()}.",
                ['de' => $previous->value, 'para' => $status->value, ...$dados],
            );

            $this->notifications->statusChanged($locked, $status);

            return $locked;
        });
    }
}
