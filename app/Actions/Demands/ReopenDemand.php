<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandStatus;
use App\Models\Demanda;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReopenDemand
{
    public function __construct(private readonly TransitionDemandStatus $transition) {}

    public function handle(Demanda $demand, User $user, ?string $motivo): Demanda
    {
        if (! $demand->status->isCompleted()) {
            throw new DomainException('Só é possível reabrir uma demanda Resolvida ou Encerrada.');
        }

        return DB::transaction(fn (): Demanda => $this->transition->handle(
            $demand,
            DemandStatus::InProgress,
            $user,
            DemandEventType::Reaberta,
            "Demanda reaberta por {$user->name}.".($motivo ? " Motivo: {$motivo}" : ''),
            array_filter(['motivo' => $motivo, 'reaberta_de' => $demand->status->value]),
        ));
    }
}
