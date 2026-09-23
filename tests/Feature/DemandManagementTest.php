<?php

namespace Tests\Feature;

use App\Enums\DemandResultado;
use App\Enums\DemandStatus;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DemandManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_demand_is_created_with_sequential_protocol_and_office_from_backend(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();
        $category = Categoria::factory()->forGabinete($office)->create();

        $this->actingAs($advisor);

        $this->post(route('demands.store'), [
            ...$this->payload($citizen, $category),
            'gabinete_id' => $otherOffice->id,
            'protocolo' => 'FALSO-999999',
            'status' => DemandStatus::Resolved->value,
        ])->assertRedirect();

        $this->post(route('demands.store'), $this->payload($citizen, $category))
            ->assertRedirect();

        $demands = Demanda::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $demands);
        $this->assertSame($office->id, $demands[0]->gabinete_id);
        $this->assertSame(now()->year.'-000001', $demands[0]->protocolo);
        $this->assertSame(now()->year.'-000002', $demands[1]->protocolo);
        $this->assertSame(DemandStatus::New, $demands[0]->status);
        $this->assertSame(2, DemandaEvento::withoutGlobalScopes()->where('tipo', 'demanda_criada')->count());
    }

    public function test_demand_can_be_created_without_category(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();

        $this->actingAs($advisor)
            ->post(route('demands.store'), [
                ...$this->payload($citizen, null),
                'categoria_id' => '',
            ])
            ->assertRedirect();

        $demand = Demanda::query()->sole();
        $this->assertNull($demand->categoria_id);
    }

    public function test_protocol_sequence_is_independent_for_each_office(): void
    {
        $firstOffice = Gabinete::factory()->create();
        $secondOffice = Gabinete::factory()->create();
        $firstUser = User::factory()->operator()->forGabinete($firstOffice)->create();
        $secondUser = User::factory()->operator()->forGabinete($secondOffice)->create();
        $firstCitizen = Cidadao::factory()->forGabinete($firstOffice)->create();
        $secondCitizen = Cidadao::factory()->forGabinete($secondOffice)->create();
        $firstCategory = Categoria::factory()->forGabinete($firstOffice)->create();
        $secondCategory = Categoria::factory()->forGabinete($secondOffice)->create();

        $this->actingAs($firstUser)->post(route('demands.store'), $this->payload($firstCitizen, $firstCategory));
        $this->actingAs($secondUser)->post(route('demands.store'), $this->payload($secondCitizen, $secondCategory));

        $this->assertSame(
            [now()->year.'-000001', now()->year.'-000001'],
            Demanda::withoutGlobalScopes()->orderBy('id')->pluck('protocolo')->all(),
        );
    }

    public function test_status_transition_and_reopening_preserve_history_and_dates(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $advisor)->create();

        $this->actingAs($advisor)
            ->patch(route('demands.transition', $demand), ['status' => DemandStatus::InProgress->value])
            ->assertRedirect(route('demands.show', $demand));

        $this->patch(route('demands.resolve', $demand), [
            'resultado' => DemandResultado::Atendida->value,
            'descricao' => 'Providência concluída.',
        ])->assertRedirect(route('demands.show', $demand));

        $demand->refresh();
        $this->assertSame(DemandStatus::Resolved, $demand->status);
        $this->assertNotNull($demand->concluida_em);
        $this->assertSame(DemandResultado::Atendida, $demand->resultado);

        $this->patch(route('demands.reopen', $demand), ['motivo' => 'Cidadão retornou.'])
            ->assertRedirect(route('demands.show', $demand));

        $demand->refresh();
        $this->assertSame(DemandStatus::InProgress, $demand->status);
        $this->assertNull($demand->concluida_em);
        $this->assertNull($demand->resultado);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'demanda_reaberta',
            'usuario_id' => $advisor->id,
        ]);
    }

    public function test_demand_can_be_closed_directly_and_reopened(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->patch(route('demands.close', $demand), ['descricao' => 'Ciclo encerrado.'])
            ->assertRedirect(route('demands.show', $demand));

        $demand->refresh();
        $this->assertSame(DemandStatus::Closed, $demand->status);
        $this->assertNotNull($demand->encerrada_em);

        $this->patch(route('demands.reopen', $demand), ['motivo' => 'Reaberta por engano.'])
            ->assertRedirect(route('demands.show', $demand));
        $this->assertNull($demand->fresh()->encerrada_em);
    }

    public function test_reopen_is_rejected_for_a_demand_that_is_not_resolved_or_closed(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->patch(route('demands.reopen', $demand))
            ->assertForbidden();

        $this->assertSame(DemandStatus::New, $demand->fresh()->status);
    }

    public function test_closing_a_demand_requires_a_description(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->patch(route('demands.close', $demand), ['descricao' => ''])
            ->assertInvalid(['descricao']);

        $this->assertSame(DemandStatus::New, $demand->fresh()->status);
    }

    public function test_reopening_a_demand_requires_a_motive(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->patch(route('demands.close', $demand), ['descricao' => 'Ciclo encerrado.'])
            ->assertRedirect(route('demands.show', $demand));

        $this->actingAs($advisor)
            ->patch(route('demands.reopen', $demand), ['motivo' => ''])
            ->assertInvalid(['motivo']);

        $this->assertSame(DemandStatus::Closed, $demand->fresh()->status);
    }

    public function test_demand_in_progress_cannot_go_back_to_new(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->patch(route('demands.transition', $demand), ['status' => DemandStatus::InProgress->value])
            ->assertRedirect(route('demands.show', $demand));

        // "Nova" é só o estado inicial: não volta a ser opção depois.
        $this->actingAs($advisor)
            ->patch(route('demands.transition', $demand), ['status' => DemandStatus::New->value])
            ->assertSessionHasErrors('status');

        $this->assertSame(DemandStatus::InProgress, $demand->fresh()->status);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [$advisor, $demand] = $this->demandContext();

        $this->actingAs($advisor)
            ->patch(route('demands.transition', $demand), ['status' => 'status-invalido'])
            ->assertSessionHasErrors('status');
    }

    public function test_only_councilor_or_chief_of_staff_can_delete_a_demand(): void
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $chief = User::factory()->administrator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $advisor)->create();

        $this->actingAs($advisor)
            ->delete(route('demands.destroy', $demand))
            ->assertForbidden();

        $this->actingAs($chief)
            ->delete(route('demands.destroy', $demand))
            ->assertRedirect(route('demands.index'));

        $this->assertSoftDeleted('demandas', ['id' => $demand->id]);
    }

    public function test_user_cannot_access_demand_from_another_office(): void
    {
        $ownOffice = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($ownOffice)->create();
        $foreignDemand = Demanda::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($user)->get(route('demands.show', $foreignDemand))->assertNotFound();
        $this->get(route('demands.edit', $foreignDemand))->assertNotFound();
        $this->put(route('demands.update', $foreignDemand), [])->assertNotFound();
    }

    public function test_inbox_defaults_to_open_demands_and_supports_search(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $category = Categoria::factory()->forGabinete($office)->create();
        Demanda::factory()->forGabinete($office, categoria: $category, creator: $user)->create([
            'titulo' => 'Iluminação da praça',
            'prioridade' => 'urgente',
            'status' => DemandStatus::InProgress,
            'prazo' => now()->subDay(),
        ]);
        Demanda::factory()->forGabinete($office, creator: $user)->create(['titulo' => 'Outra solicitação']);
        Demanda::factory()->forGabinete($office, creator: $user)->create([
            'titulo' => 'Já resolvida',
            'status' => DemandStatus::Resolved,
        ]);

        $this->actingAs($user)->get(route('demands.index', [
            'q' => 'Iluminação',
            'prioridade' => 'urgente',
        ]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('demands/index')
            ->where('filters.tab', 'inbox')
            ->has('demands.data', 1)
            ->where('demands.data.0.titulo', 'Iluminação da praça')
            ->where('demands.data.0.atrasada', true));

        $this->get(route('demands.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('demands.data', 2)
            ->where('tabCounts.inbox', 2)
            ->where('tabCounts.overdue', 1));
    }

    public function test_mine_and_awaiting_tabs_scope_the_list(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $other = User::factory()->operator()->forGabinete($office)->create();
        Demanda::factory()->forGabinete($office, creator: $user)->create(['responsavel_id' => $user->id]);
        Demanda::factory()->forGabinete($office, creator: $user)->create(['responsavel_id' => $other->id]);
        Demanda::factory()->forGabinete($office, creator: $user)->create(['status' => DemandStatus::Awaiting]);

        $this->actingAs($user)
            ->get(route('demands.index', ['tab' => 'mine']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('demands.data', 1));

        $this->get(route('demands.index', ['tab' => 'awaiting']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('demands.data', 1));
    }

    public function test_favoriting_a_demand_always_sorts_it_first(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $older = Demanda::factory()->forGabinete($office, creator: $user)->create([
            'titulo' => 'Mais antiga',
            'aberta_em' => now()->subDays(5),
            'ultima_atividade_em' => now()->subDays(5),
        ]);
        $newer = Demanda::factory()->forGabinete($office, creator: $user)->create([
            'titulo' => 'Mais recente',
            'aberta_em' => now(),
            'ultima_atividade_em' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('demands.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('demands.data.0.id', $newer->id)
                ->where('demands.data.0.favoritada_em', null));

        $this->patch(route('demands.favorite.toggle', $older))
            ->assertRedirect();

        $older->refresh();
        $this->assertNotNull($older->favoritada_em);
        $this->assertSame($user->id, $older->favoritada_por_id);

        $this->get(route('demands.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('demands.data.0.id', $older->id)
                ->where('demands.data.0.favoritada_por.name', $user->name));

        $this->patch(route('demands.favorite.toggle', $older))->assertRedirect();
        $this->assertNull($older->fresh()->favoritada_em);

        $this->get(route('demands.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('demands.data.0.id', $newer->id));
    }

    public function test_favoriting_another_offices_demand_is_rejected(): void
    {
        [$user] = $this->demandContext();
        $foreignOffice = Gabinete::factory()->create();
        $foreignDemand = Demanda::factory()->forGabinete($foreignOffice)->create();

        $this->actingAs($user)
            ->patch(route('demands.favorite.toggle', $foreignDemand))
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function payload(Cidadao $citizen, ?Categoria $category, ?Bairro $neighborhood = null): array
    {
        return [
            'cidadao_id' => $citizen->id,
            'titulo' => 'Solicitação de melhoria urbana',
            'descricao' => 'O cidadão solicita providências para a via.',
            'categoria_id' => $category?->id,
            'bairro_id' => $neighborhood?->id,
            'responsavel_id' => null,
            'endereco' => 'Rua das Flores',
            'numero' => '100',
            'prioridade' => 'normal',
            'origem' => 'whatsapp',
            'prazo' => now()->addDays(10)->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array{User, Demanda} */
    private function demandContext(): array
    {
        $office = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $advisor)->create();

        return [$advisor, $demand];
    }
}
