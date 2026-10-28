<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Citizen;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attendance> */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Office::factory(),
            'cidadao_id' => Citizen::factory(),
            'atendente_id' => User::factory(),
            'demanda_id' => null,
            'criado_por_id' => User::factory(),
            'visita_domiciliar' => false,
            'compromisso_id' => null,
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
        Office $gabinete,
        ?Citizen $citizen = null,
        ?User $attendant = null,
        ?User $creator = null,
    ): static {
        $citizen ??= Citizen::factory()->forGabinete($gabinete)->create();
        $attendant ??= User::factory()->operator()->forGabinete($gabinete)->create();
        $creator ??= $attendant;

        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'cidadao_id' => $citizen->id,
            'atendente_id' => $attendant->id,
            'criado_por_id' => $creator->id,
        ]);
    }
}
