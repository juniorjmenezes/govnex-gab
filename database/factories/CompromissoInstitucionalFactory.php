<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\CompromissoInstitucional;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompromissoInstitucional> */
class CompromissoInstitucionalFactory extends Factory
{
    protected $model = CompromissoInstitucional::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 30))->setTime(fake()->numberBetween(8, 16), 0);

        return [
            'criado_por_gabinete_id' => Office::factory(),
            'entidade_id' => fn (array $attributes) => Office::withoutGlobalScopes()
                ->whereKey($attributes['criado_por_gabinete_id'])
                ->value('entidade_id'),
            'criado_por_id' => fn (array $attributes) => User::factory()->state(['gabinete_id' => $attributes['criado_por_gabinete_id']]),
            'titulo' => fake()->sentence(4),
            'descricao' => fake()->sentence(),
            'inicio_em' => $start,
            'fim_em' => $start->copy()->addHours(2),
            'dia_inteiro' => false,
            'local' => 'Plenário',
            'tipo' => 'Sessão ordinária',
            'status' => AppointmentStatus::Scheduled,
        ];
    }

    /** Publicado pela Secretaria legislativa (e na entidade dela). */
    public function publishedBy(Office $secretaria, ?User $author = null): static
    {
        return $this->state(fn (): array => array_filter([
            'criado_por_gabinete_id' => $secretaria->id,
            'entidade_id' => $secretaria->entidade_id,
            'criado_por_id' => $author?->id,
        ]));
    }

    /** @param list<Office> $gabinetes */
    public function targeting(array $gabinetes): static
    {
        return $this->afterCreating(function (CompromissoInstitucional $compromisso) use ($gabinetes): void {
            $compromisso->gabinetesAlvo()->sync(array_map(fn (Office $gabinete): int => $gabinete->id, $gabinetes));
        });
    }
}
