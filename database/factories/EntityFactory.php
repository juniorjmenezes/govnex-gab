<?php

namespace Database\Factories;

use App\Enums\EntityStatus;
use App\Enums\EntityType;
use App\Models\Entity;
use App\Services\Entidades\EntityEntitlementService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @extends Factory<Entity> */
class EntityFactory extends Factory
{
    protected $model = Entity::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Entity $entidade): void {
            if (Schema::hasTable('entidade_modulos')) {
                app(EntityEntitlementService::class)->provisionLegacyCompatible($entidade);
            }
        });
    }

    public function definition(): array
    {
        $name = 'Organização '.fake()->unique()->company();

        return [
            'tipo' => EntityType::IndependentOffice,
            'nome' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'status' => EntityStatus::Active,
            'municipio' => fake()->city(),
            'estado' => fake()->randomElement(['CE', 'SP', 'MG', 'BA', 'PE']),
            'timezone' => 'America/Fortaleza',
            'interface_simplificada' => true,
        ];
    }
}
