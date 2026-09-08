<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandStatus;
use App\Models\Demanda;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloseDemand
{
    public function __construct(private readonly TransitionDemandStatus $transition) {}

    public function handle(Demanda $demand, User $user, ?string $descricao): Demanda
    {
        return DB::transaction(fn (): Demanda => $this->transition->handle(
            $demand,
            DemandStatus::Closed,
            $user,
            DemandEventType::Encerrada,
            $descricao ?: 'Demanda encerrada.',
            array_filter(['descricao' => $descricao]),
        ));
    }
}
