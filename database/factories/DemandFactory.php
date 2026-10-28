<?php

namespace Database\Factories;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Models\Category;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\Neighborhood;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Demand> */
class DemandFactory extends Factory
{
    protected $model = Demand::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Office::factory(),
            'protocolo' => now()->year.'-'.fake()->unique()->numerify('######'),
            'cidadao_id' => Citizen::factory(),
            'categoria_id' => Category::factory(),
            'bairro_id' => null,
            'responsavel_id' => null,
            'criado_por_id' => User::factory(),
            'titulo' => fake()->sentence(5),
            'descricao' => fake()->paragraph(),
            'prioridade' => DemandPriority::Normal,
            'status' => DemandStatus::New,
            'origem' => DemandOrigin::WhatsApp,
            'aberta_em' => now(),
            'prazo' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'concluida_em' => null,
        ];
    }

    public function forGabinete(
        Office $gabinete,
        ?Citizen $cidadao = null,
        ?Category $categoria = null,
        ?User $creator = null,
        ?Neighborhood $bairro = null,
    ): static {
        $cidadao ??= Citizen::factory()->forGabinete($gabinete, $bairro)->create();
        $categoria ??= Category::factory()->forGabinete($gabinete)->create();
        $creator ??= User::factory()->operator()->forGabinete($gabinete)->create();

        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'cidadao_id' => $cidadao->id,
            'categoria_id' => $categoria->id,
            'bairro_id' => $bairro?->id,
            'criado_por_id' => $creator->id,
        ]);
    }

    public function status(DemandStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'concluida_em' => in_array($status, [DemandStatus::Resolved, DemandStatus::Closed], true) ? now() : null,
            'encerrada_em' => $status === DemandStatus::Closed ? now() : null,
        ]);
    }
}
