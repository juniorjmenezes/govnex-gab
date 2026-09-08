<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Gabinete;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Categoria> */
class CategoriaFactory extends Factory
{
    protected $model = Categoria::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
            'nome' => fake()->unique()->words(2, true),
            'descricao' => fake()->sentence(),
            'icone' => 'tag',
            'cor_semantica' => 'neutra',
            'ativo' => true,
        ];
    }

    public function forGabinete(Gabinete $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->id]);
    }
}
