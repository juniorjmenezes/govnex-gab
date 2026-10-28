<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Office::factory(),
            'nome' => fake()->unique()->words(2, true),
            'descricao' => fake()->sentence(),
            'icone' => 'tag',
            'cor_semantica' => 'neutra',
            'ativo' => true,
        ];
    }

    public function forGabinete(Office $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->id]);
    }
}
