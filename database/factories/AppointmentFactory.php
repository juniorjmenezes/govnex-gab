<?php

namespace Database\Factories;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 30))->setTime(fake()->numberBetween(8, 16), 0);

        return [
            'gabinete_id' => Gabinete::factory(),
            'responsavel_id' => fn (array $attributes) => User::factory()->state(['gabinete_id' => $attributes['gabinete_id']]),
            'criado_por_id' => fn (array $attributes) => User::factory()->state(['gabinete_id' => $attributes['gabinete_id']]),
            'titulo' => fake()->sentence(4),
            'descricao' => fake()->sentence(),
            'inicio_em' => $start,
            'fim_em' => $start->copy()->addHour(),
            'dia_inteiro' => false,
            'local' => fake()->streetAddress(),
            'tipo' => 'reuniao',
            'status' => AppointmentStatus::Scheduled,
            'recorrencia' => AppointmentRecurrence::None,
        ];
    }

    public function forGabinete(Gabinete|int $gabinete): static
    {
        $id = $gabinete instanceof Gabinete ? $gabinete->id : $gabinete;

        return $this->state(fn (): array => ['gabinete_id' => $id]);
    }
}
