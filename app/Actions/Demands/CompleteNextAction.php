<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Models\Demand;
use App\Models\User;
use App\Services\Demands\DemandTimelineRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CompleteNextAction
{
    public function __construct(private readonly DemandTimelineRecorder $timeline) {}

    public function handle(Demand $demand, User $user): Demand
    {
        if (! $demand->hasNextActionPending()) {
            throw new DomainException('Não há próxima ação pendente para concluir.');
        }

        return DB::transaction(function () use ($demand, $user): Demand {
            $demand->forceFill(['proxima_acao_concluida_em' => now()])->save();

            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::ProximaAcaoConcluida,
                $demand->proxima_acao_descricao,
            );

            return $demand;
        });
    }
}
