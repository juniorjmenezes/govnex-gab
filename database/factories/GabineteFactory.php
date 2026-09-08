<?php

namespace Database\Factories;

use App\Enums\GabineteModule;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\Entidade;
use App\Models\Gabinete;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<Gabinete> */
class GabineteFactory extends Factory
{
    protected $model = Gabinete::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Gabinete $office): void {
            $now = now();

            DB::table('gabinete_modulos')->insertOrIgnore(array_map(
                fn (GabineteModule $module): array => [
                    'gabinete_id' => $office->id,
                    'modulo' => $module->value,
                    'ativo' => true,
                    'ativado_em' => $now,
                    'desativado_em' => null,
                    'administrador_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                GabineteModule::cases(),
            ));
        });
    }

    public function definition(): array
    {
        $nome = 'Gabinete '.fake()->unique()->company();

        return [
            'entidade_id' => Entidade::factory(),
            'tipo_gabinete' => GabineteType::IndependentOffice,
            'nome' => $nome,
            'slug' => Str::slug($nome).'-'.fake()->unique()->numerify('###'),
            'status' => GabineteStatus::Active,
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
            'status' => GabineteStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
