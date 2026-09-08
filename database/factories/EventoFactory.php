<?php

namespace Database\Factories;

use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Evento;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Evento> */
class EventoFactory extends Factory
{
    protected $model = Evento::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 30))->setHour(9);

        return [
            'gabinete_id' => Gabinete::factory(),
            'responsavel_id' => null,
            'criado_por_id' => User::factory(),
            'titulo' => fake()->sentence(5),
            'tipo' => fake()->randomElement(EventType::cases()),
            'status' => EventStatus::Planned,
            'duracao' => EventDuration::SingleDay,
            'inicio_em' => $start,
            'fim_em' => $start->copy()->addHours(2),
            'local' => fake()->optional()->address(),
            'descricao' => fake()->optional()->paragraph(),
            'observacoes' => null,
        ];
    }

    public function forGabinete(
        Gabinete $gabinete,
        ?User $responsible = null,
        ?User $creator = null,
    ): static {
        $creator ??= User::factory()->advisor()->forGabinete($gabinete)->create();

        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'responsavel_id' => $responsible?->id,
            'criado_por_id' => $creator->id,
        ]);
    }
}
