<?php

namespace Tests\Feature;

use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DemandNextActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_next_action_can_be_defined_and_notifies_the_assigned_responsible(): void
    {
        [$user, $demand] = $this->demandContext();
        $responsible = User::factory()->operator()->forGabinete($user->gabinete)->create();

        $this->actingAs($user)
            ->post(route('demands.next-action.store', $demand), [
                'descricao' => 'Confirmar retorno com o cidadão',
                'data' => now()->addDays(2)->toDateString(),
                'responsavel_id' => $responsible->id,
            ])
            ->assertRedirect(route('demands.show', $demand));

        $demand->refresh();
        $this->assertSame('Confirmar retorno com o cidadão', $demand->proxima_acao_descricao);
        $this->assertSame($responsible->id, $demand->proxima_acao_responsavel_id);
        $this->assertNull($demand->proxima_acao_concluida_em);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'proxima_acao_definida',
        ]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_defining_a_new_next_action_replaces_the_previous_one(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Primeira ação',
            'data' => now()->addDay()->toDateString(),
        ]);
        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Segunda ação',
            'data' => now()->addDays(3)->toDateString(),
        ]);

        $demand->refresh();
        $this->assertSame('Segunda ação', $demand->proxima_acao_descricao);
    }

    public function test_editing_a_still_pending_action_does_not_duplicate_the_timeline_event(): void
    {
        [$user, $demand] = $this->demandContext();

        // A primeira ação nunca foi concluída, então a segunda chamada é uma
        // edição dela (ex.: usuário corrigindo a data pelo lápis) — não uma
        // nova definição.
        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Primeira ação',
            'data' => now()->addDay()->toDateString(),
        ]);
        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Segunda ação',
            'data' => now()->addDays(3)->toDateString(),
        ]);

        $this->assertSame(
            1,
            $demand->eventos()->where('tipo', 'proxima_acao_definida')->count(),
        );
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_defining_a_next_action_after_completing_the_previous_one_records_a_new_event(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Primeira ação',
            'data' => now()->addDay()->toDateString(),
        ]);
        $this->actingAs($user)->patch(route('demands.next-action.complete', $demand));
        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Segunda ação',
            'data' => now()->addDays(3)->toDateString(),
        ]);

        $this->assertSame(
            2,
            $demand->eventos()->where('tipo', 'proxima_acao_definida')->count(),
        );
    }

    public function test_next_action_can_be_completed(): void
    {
        [$user, $demand] = $this->demandContext();
        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Ligar para o cidadão',
            'data' => now()->toDateString(),
        ]);

        $this->patch(route('demands.next-action.complete', $demand))
            ->assertRedirect(route('demands.show', $demand));

        $demand->refresh();
        $this->assertNotNull($demand->proxima_acao_concluida_em);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'proxima_acao_concluida',
        ]);
    }

    public function test_next_action_without_a_date_can_still_be_completed(): void
    {
        [$user, $demand] = $this->demandContext();
        $this->actingAs($user)->post(route('demands.next-action.store', $demand), [
            'descricao' => 'Ligar para o cidadão',
        ]);

        $demand->refresh();
        $this->assertTrue($demand->hasNextActionPending());
        $this->assertFalse($demand->isNextActionOverdue());

        $this->patch(route('demands.next-action.complete', $demand))
            ->assertRedirect(route('demands.show', $demand));

        $this->assertNotNull($demand->fresh()->proxima_acao_concluida_em);
    }

    public function test_completing_without_a_pending_next_action_fails(): void
    {
        [$user, $demand] = $this->demandContext();

        $this->actingAs($user)
            ->patch(route('demands.next-action.complete', $demand))
            ->assertStatus(500);
    }

    public function test_inbox_today_and_overdue_tabs_reflect_next_action_dates(): void
    {
        Carbon::setTestNow('2026-08-24 10:00:00');
        [$user, $demandToday] = $this->demandContext();
        [, $demandOverdue] = $this->demandContext($user->gabinete);
        [, $demandFuture] = $this->demandContext($user->gabinete);

        $this->actingAs($user);
        $this->post(route('demands.next-action.store', $demandToday), [
            'descricao' => 'Hoje', 'data' => now()->toDateString(),
        ]);
        $this->post(route('demands.next-action.store', $demandOverdue), [
            'descricao' => 'Atrasada', 'data' => now()->subDays(2)->toDateString(),
        ]);
        $this->post(route('demands.next-action.store', $demandFuture), [
            'descricao' => 'Futura', 'data' => now()->addWeek()->toDateString(),
        ]);

        $this->get(route('demands.index', ['tab' => 'today']))
            ->assertInertia(fn ($page) => $page->has('demands.data', 1)
                ->where('demands.data.0.id', $demandToday->id));

        $this->get(route('demands.index', ['tab' => 'overdue']))
            ->assertInertia(fn ($page) => $page->has('demands.data', 1)
                ->where('demands.data.0.id', $demandOverdue->id));
    }

    /** @return array{User, Demanda} */
    private function demandContext(?Gabinete $office = null): array
    {
        $office ??= Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office, creator: $user)->create();

        return [$user, $demand];
    }
}
