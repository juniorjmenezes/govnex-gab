<?php

namespace Database\Factories;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Demanda> */
class DemandaFactory extends Factory
{
    protected $model = Demanda::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
            'protocolo' => now()->year.'-'.fake()->unique()->numerify('######'),
            'cidadao_id' => Cidadao::factory(),
            'categoria_id' => Categoria::factory(),
            'bairro_id' => null,
            'responsavel_id' => null,
            'criado_por_id' => User::factory(),
            'titulo' => fake()->sentence(5),
            'descricao' => fake()->paragraph(),
            'prioridade' => DemandPriority::Normal,
            'status' => DemandStatus::New,
            'origem' => DemandOrigin::WhatsApp,
            'aberta_em' => now(),
            'prazo' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'concluida_em' => null,
        ];
    }

    public function forGabinete(
        Gabinete $gabinete,
        ?Cidadao $cidadao = null,
        ?Categoria $categoria = null,
        ?User $creator = null,
        ?Bairro $bairro = null,
    ): static {
        $cidadao ??= Cidadao::factory()->forGabinete($gabinete, $bairro)->create();
        $categoria ??= Categoria::factory()->forGabinete($gabinete)->create();
        $creator ??= User::factory()->advisor()->forGabinete($gabinete)->create();

        return $this->state(fn (): array => [
            'gabinete_id' => $gabinete->id,
            'cidadao_id' => $cidadao->id,
            'categoria_id' => $categoria->id,
            'bairro_id' => $bairro?->id,
            'criado_por_id' => $creator->id,
        ]);
    }

    public function status(DemandStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'concluida_em' => in_array($status, [DemandStatus::Resolved, DemandStatus::Closed], true) ? now() : null,
            'encerrada_em' => $status === DemandStatus::Closed ? now() : null,
        ]);
    }
}
