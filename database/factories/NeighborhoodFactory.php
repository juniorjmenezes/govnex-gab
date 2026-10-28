<?php

namespace Database\Factories;

use App\Models\Neighborhood;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Neighborhood> */
class NeighborhoodFactory extends Factory
{
    protected $model = Neighborhood::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Office::factory(),
            'nome' => fake()->citySuffix().' '.fake()->unique()->streetName(),
            'municipio' => fake()->city(),
            'estado' => fake()->randomElement(['CE', 'SP', 'MG', 'BA', 'PE']),
            'ativo' => true,
        ];
    }

    public function forGabinete(Office $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->id]);
    }
}
