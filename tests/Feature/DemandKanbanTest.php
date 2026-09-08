<?php

namespace Tests\Feature;

use App\Enums\DemandStatus;
use App\Models\Demanda;
use App\Models\DemandaAnexo;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DemandKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_groups_only_office_demands_and_exposes_allowed_transitions(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $advisor)->create();
        $foreignOffice = Gabinete::factory()->create();
        Demanda::factory()->forGabinete($foreignOffice)->create();

        $this->actingAs($advisor);
        $attachment = new DemandaAnexo;
        $attachment->forceFill([
            'gabinete_id' => $office->id,
            'demanda_id' => $demand->id,
            'usuario_id' => $advisor->id,
            'disk' => 'local',
            'caminho' => 'private/teste.pdf',
            'nome_original' => 'teste.pdf',
            'nome_armazenado' => 'seguro.pdf',
            'mime_type' => 'application/pdf',
            'extensao' => 'pdf',
            'tamanho' => 100,
            'imagem' => false,
        ])->save();

        $this->get(route('demands.kanban'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('demands/kanban')
                ->has('columns', count(DemandStatus::cases()))
                ->where('columns.0.status', DemandStatus::New->value)
                ->where('columns.0.total', 1)
                ->has('columns.0.demands', 1)
                ->where('columns.0.demands.0.id', $demand->id)
                ->where('columns.0.demands.0.anexos_count', 1)
                ->where('columns.0.demands.0.allowed_transitions', [
                    DemandStatus::InProgress->value,
                    DemandStatus::Awaiting->value,
                    DemandStatus::Resolved->value,
                    DemandStatus::Closed->value,
                ]));
    }

    public function test_kanban_filters_are_processed_on_server(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        Demanda::factory()->forGabinete($office, creator: $advisor)->create([
            'titulo' => 'Iluminação da praça',
            'prioridade' => 'urgente',
        ]);
        Demanda::factory()->forGabinete($office, creator: $advisor)->create([
            'titulo' => 'Consulta de saúde',
            'prioridade' => 'normal',
        ]);

        $this->actingAs($advisor)
            ->get(route('demands.kanban', [
                'q' => 'Iluminação',
                'prioridade' => 'urgente',
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.q', 'Iluminação')
                ->where('filters.prioridade', 'urgente')
                ->where('columns.0.total', 1)
                ->where('columns.0.demands.0.titulo', 'Iluminação da praça'));
    }

    public function test_kanban_move_uses_domain_transition_and_creates_timeline_event(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->from(route('demands.kanban'))
            ->patch(route('demands.kanban.transition', $demand), [
                'status' => DemandStatus::Awaiting->value,
            ])
            ->assertRedirect(route('demands.kanban'));

        $this->assertSame(DemandStatus::Awaiting, $demand->fresh()->status);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'usuario_id' => $advisor->id,
            'tipo' => 'status_alterado',
        ]);
    }

    public function test_invalid_kanban_move_is_rejected_without_changing_demand(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->from(route('demands.kanban'))
            ->patch(route('demands.kanban.transition', $demand), [
                'status' => 'status-invalido',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(DemandStatus::New, $demand->fresh()->status);
        $this->assertDatabaseMissing('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'status_alterado',
        ]);
    }

    public function test_kanban_transition_cannot_access_another_office_demand(): void
    {
        [$advisor] = $this->demandContext();
        $foreignOffice = Gabinete::factory()->create();
        $foreignDemand = Demanda::factory()->forGabinete($foreignOffice)->create();

        $this->actingAs($advisor)
            ->patch(route('demands.kanban.transition', $foreignDemand), [
                'status' => DemandStatus::InProgress->value,
            ])
            ->assertNotFound();
    }

    /** @return array{User, Demanda} */
    private function demandContext(): array
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $advisor)->create();

        return [$advisor, $demand];
    }
}
