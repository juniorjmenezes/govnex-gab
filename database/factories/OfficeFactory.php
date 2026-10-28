<?php

namespace Database\Factories;

use App\Enums\OfficeModule;
use App\Enums\OfficeStatus;
use App\Enums\OfficeType;
use App\Models\Entity;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<Office> */
class OfficeFactory extends Factory
{
    protected $model = Office::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Office $office): void {
            $now = now();

            DB::table('gabinete_modulos')->insertOrIgnore(array_map(
                fn (OfficeModule $module): array => [
                    'gabinete_id' => $office->id,
                    'modulo' => $module->value,
                    'ativo' => true,
                    'ativado_em' => $now,
                    'desativado_em' => null,
                    'administrador_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_values(array_filter(
                    OfficeModule::cases(),
                    fn (OfficeModule $module): bool => $module->availableFor($office->tipo_gabinete),
                )),
            ));
        });
    }

    public function definition(): array
    {
        $nome = 'Gabinete '.fake()->unique()->company();

        return [
            'entidade_id' => Entity::factory(),
            'tipo_gabinete' => OfficeType::IndependentOffice,
            'nome' => $nome,
            'slug' => Str::slug($nome).'-'.fake()->unique()->numerify('###'),
            'status' => OfficeStatus::Active,
            'vereador_nome' => fake()->name(),
            'municipio' => fake()->city(),
            'estado' => fake()->randomElement(['CE', 'SP', 'MG', 'BA', 'PE']),
            'telefone' => fake()->numerify('(##) #####-####'),
            'email' => fake()->unique()->safeEmail(),
            'endereco' => fake()->streetAddress(),
            'formato_protocolo' => '{ANO}-{SEQUENCIAL}',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => OfficeStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
