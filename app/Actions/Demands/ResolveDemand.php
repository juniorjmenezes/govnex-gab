<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandResultado;
use App\Enums\DemandStatus;
use App\Models\Demand;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResolveDemand
{
    public function __construct(private readonly TransitionDemandStatus $transition) {}

    public function handle(Demand $demand, User $user, ?DemandResultado $resultado, ?string $descricao): Demand
    {
        return DB::transaction(fn (): Demand => $this->transition->handle(
            $demand,
            DemandStatus::Resolved,
            $user,
            DemandEventType::Resolvida,
            $descricao ?: 'Demanda marcada como resolvida.',
            array_filter(['resultado' => $resultado?->value, 'descricao_final' => $descricao]),
            $resultado,
        ));
    }
}
