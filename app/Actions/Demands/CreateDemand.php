<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandStatus;
use App\Models\Demand;
use App\Models\User;
use App\Services\Demands\DemandNotificationService;
use App\Services\Demands\DemandProtocolGenerator;
use App\Services\Demands\DemandTimelineRecorder;
use App\Tenancy\OfficeContext;
use Illuminate\Support\Facades\DB;

class CreateDemand
{
    public function __construct(
        private readonly DemandProtocolGenerator $protocols,
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandNotificationService $notifications,
        private readonly OfficeContext $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $user): Demand
    {
        return DB::transaction(function () use ($data, $user): Demand {
            $office = $this->context->requireOfficeFor($user);
            $openedAt = now();
            $protocol = $this->protocols->next(
                $office->id,
                $openedAt->year,
                $office->formato_protocolo ?: '{ANO}-{SEQUENCIAL}',
            );

            $demand = new Demand;
            $demand->forceFill([
                ...$data,
                'gabinete_id' => $office->id,
                'protocolo' => $protocol,
                'status' => DemandStatus::New,
                'criado_por_id' => $user->id,
                'aberta_em' => $openedAt,
                'ultima_atividade_em' => $openedAt,
                'concluida_em' => null,
            ])->save();

            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::Criada,
                "Demanda {$protocol} criada.",
            );

            $this->notifications->assigned($demand);

            return $demand;
        });
    }
}
