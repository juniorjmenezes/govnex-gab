<?php

namespace Database\Factories;

use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 30))->setHour(9);

        return [
            'gabinete_id' => Office::factory(),
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
        Office $gabinete,
        ?User $responsible = null,
        ?User $creator = null,
    ): static {
        $creator ??= User::factory()->operator()->forGabinete($gabinete)->create();

        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'responsavel_id' => $responsible?->id,
            'criado_por_id' => $creator->id,
        ]);
    }
}
