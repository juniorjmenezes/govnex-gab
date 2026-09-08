<?php

namespace Database\Factories;

use App\Models\Atendimento;
use App\Models\Cidadao;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Atendimento> */
class AtendimentoFactory extends Factory
{
    protected $model = Atendimento::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
            'cidadao_id' => Cidadao::factory(),
            'atendente_id' => User::factory(),
            'demanda_id' => null,
            'criado_por_id' => User::factory(),
            'assunto' => fake()->sentence(5),
            'relato' => fake()->paragraph(),
            'providencias' => fake()->optional()->paragraph(),
            'atendido_em' => now()->subMinutes(fake()->numberBetween(5, 240)),
            'duracao_minutos' => fake()->numberBetween(10, 90),
            'requer_retorno' => false,
            'retorno_previsto_em' => null,
        ];
    }

    public function forGabinete(
        Gabinete $gabinete,
        ?Cidadao $citizen = null,
        ?User $attendant = null,
        ?User $creator = null,
    ): static {
        $citizen ??= Cidadao::factory()->forGabinete($gabinete)->create();
        $attendant ??= User::factory()->advisor()->forGabinete($gabinete)->create();
        $creator ??= $attendant;

        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'cidadao_id' => $citizen->id,
            'atendente_id' => $attendant->id,
            'criado_por_id' => $creator->id,
        ]);
    }
}
