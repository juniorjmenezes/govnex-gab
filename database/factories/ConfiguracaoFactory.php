<?php

namespace Database\Factories;

use App\Models\Configuracao;
use App\Models\Gabinete;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Configuracao> */
class ConfiguracaoFactory extends Factory
{
    protected $model = Configuracao::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
            'partido' => fake()->randomElement(['PSD', 'MDB', 'PSB', 'PT', 'UNIÃO']),
            'legislatura' => '2025–2028',
        ];
    }

    public function forGabinete(Gabinete $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->id]);
    }
}
