<?php

namespace Database\Factories;

use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Models\Entidade;
use App\Services\Entidades\EntidadeEntitlementService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @extends Factory<Entidade> */
class EntidadeFactory extends Factory
{
    protected $model = Entidade::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Entidade $entidade): void {
            if (Schema::hasTable('entidade_modulos')) {
                app(EntidadeEntitlementService::class)->provisionLegacyCompatible($entidade);
            }
        });
    }

    public function definition(): array
    {
        $name = 'Organização '.fake()->unique()->company();

        return [
            'tipo' => EntidadeType::IndependentOffice,
            'nome' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'status' => EntidadeStatus::Active,
            'municipio' => fake()->city(),
            'estado' => fake()->randomElement(['CE', 'SP', 'MG', 'BA', 'PE']),
            'timezone' => 'America/Fortaleza',
            'interface_simplificada' => true,
        ];
    }
}
