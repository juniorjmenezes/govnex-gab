<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandStatus;
use App\Models\Demand;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReopenDemand
{
    public function __construct(private readonly TransitionDemandStatus $transition) {}

    public function handle(Demand $demand, User $user, ?string $motivo): Demand
    {
        if (! $demand->status->isCompleted()) {
            throw new DomainException('Só é possível reabrir uma demanda Resolvida ou Encerrada.');
        }

        return DB::transaction(fn (): Demand => $this->transition->handle(
            $demand,
            DemandStatus::InProgress,
            $user,
            DemandEventType::Reaberta,
            "Demanda reaberta por {$user->name}.".($motivo ? " Motivo: {$motivo}" : ''),
            array_filter(['motivo' => $motivo, 'reaberta_de' => $demand->status->value]),
        ));
    }
}
