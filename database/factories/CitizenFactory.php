<?php

namespace Database\Factories;

use App\Models\Citizen;
use App\Models\Neighborhood;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Citizen> */
class CitizenFactory extends Factory
{
    protected $model = Citizen::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Office::factory(),
            'bairro_id' => null,
            'nome' => fake()->name(),
            'cpf' => null,
            'telefone' => fake()->numerify('85#########'),
            'whatsapp' => fake()->numerify('85#########'),
            'email' => fake()->optional()->safeEmail(),
            'data_nascimento' => fake()->optional()->dateTimeBetween('-80 years', '-16 years'),
            'endereco' => fake()->streetName(),
            'numero' => fake()->buildingNumber(),
            'complemento' => fake()->optional()->word(),
            'ponto_referencia' => fake()->optional()->sentence(4),
            'latitude' => null,
            'longitude' => null,
            'localizacao_origem' => null,
            'observacoes' => fake()->optional()->sentence(),
            'consentimento_contato' => true,
            'eleitor' => false,
            'cadastrado_em' => now(),
        ];
    }

    public function forGabinete(Office $gabinete, ?Neighborhood $bairro = null): static
    {
        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'bairro_id' => $bairro?->id,
        ]);
    }
}
