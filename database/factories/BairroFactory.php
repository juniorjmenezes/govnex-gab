<?php

namespace Database\Factories;

use App\Models\Bairro;
use App\Models\Gabinete;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bairro> */
class BairroFactory extends Factory
{
    protected $model = Bairro::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
            'nome' => fake()->citySuffix().' '.fake()->unique()->streetName(),
            'municipio' => fake()->city(),
            'estado' => fake()->randomElement(['CE', 'SP', 'MG', 'BA', 'PE']),
            'ativo' => true,
        ];
    }

    public function forGabinete(Gabinete $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->id]);
    }
}
