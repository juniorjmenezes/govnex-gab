<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandStatus;
use App\Models\Demand;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloseDemand
{
    public function __construct(private readonly TransitionDemandStatus $transition) {}

    public function handle(Demand $demand, User $user, ?string $descricao): Demand
    {
        return DB::transaction(fn (): Demand => $this->transition->handle(
            $demand,
            DemandStatus::Closed,
            $user,
            DemandEventType::Encerrada,
            $descricao ?: 'Demanda encerrada.',
            array_filter(['descricao' => $descricao]),
        ));
    }
}
