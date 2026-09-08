<?php

namespace Database\Factories;

use App\Models\Bairro;
use App\Models\Cidadao;
use App\Models\Gabinete;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cidadao> */
class CidadaoFactory extends Factory
{
    protected $model = Cidadao::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
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

    public function forGabinete(Gabinete $gabinete, ?Bairro $bairro = null): static
    {
        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'bairro_id' => $bairro?->id,
        ]);
    }
}
