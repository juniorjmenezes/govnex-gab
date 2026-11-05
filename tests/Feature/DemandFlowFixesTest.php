<?php

namespace Tests\Feature;

use App\Enums\DemandStatus;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\Office;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DemandFlowFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_search_filters_by_name_and_is_scoped_to_the_office(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        Citizen::factory()->forGabinete($office)->create(['nome' => 'Zélia Araújo']);
        Citizen::factory()->forGabinete($office)->create(['nome' => 'Bruno Costa']);
        Citizen::factory()->forGabinete(Office::factory()->create())->create(['nome' => 'Zélia de Outro Gabinete']);

        $this->actingAs($user)
            ->getJson(route('demands.citizens.search', ['q' => 'Zélia']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.nome', 'Zélia Araújo');
    }

    public function test_citizen_search_requires_authentication(): void
    {
        $this->getJson(route('demands.citizens.search'))->assertUnauthorized();
    }

    public function test_form_options_do_not_preload_the_citizen_list_but_keep_the_current_one_on_edit(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        Citizen::factory()->forGabinete($office)->count(3)->create();
        $citizen = Citizen::factory()->forGabinete($office)->create(['nome' => 'Zuleica Última']);
        $demand = Demand::factory()->forGabinete($office, creator: $user)->create(['cidadao_id' => $citizen->id]);

        $this->actingAs($user)
            ->get(route('demands.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('options.citizens', 0));

        $this->get(route('demands.edit', $demand))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('options.citizens', 1)
                ->where('options.citizens.0.id', $citizen->id));
    }

    public function test_completing_a_next_action_that_does_not_exist_returns_an_error_toast_instead_of_500(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $demand = Demand::factory()->forGabinete($office, creator: $user)->create();

        $this->actingAs($user)
            ->from(route('demands.show', $demand))
            ->patch(route('demands.next-action.complete', $demand))
            ->assertRedirect(route('demands.show', $demand))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Não há próxima ação pendente para concluir.']);
    }

    public function test_a_domain_exception_from_a_lost_race_is_rendered_as_an_error_toast(): void
    {
        // A validação do request já barra a transição inválida; a exceção só
        // chega ao handler quando o estado muda entre a validação e o lock.
        Route::middleware('web')->get('/_test/domain-exception', function (): void {
            throw new DomainException('A transição não é permitida.');
        });

        $this->from('/origem')
            ->get('/_test/domain-exception')
            ->assertRedirect('/origem')
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A transição não é permitida.']);

        $this->getJson('/_test/domain-exception')->assertStatus(500);
    }

    public function test_responsible_list_matches_the_validation_rule(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $eligible = User::factory()->operator()->forGabinete($office)->create();
        $withoutPosition = User::factory()->operator()->forGabinete($office)->withoutPosition()->create();
        $inactive = User::factory()->operator()->forGabinete($office)->inactive()->create();
        $root = User::factory()->root()->create(['gabinete_id' => $office->id]);
        User::factory()->operator()->forGabinete(Office::factory()->create())->create();

        $this->actingAs($user)
            ->get(route('demands.index'))
            ->assertInertia(function (AssertableInertia $page) use ($user, $eligible, $withoutPosition, $inactive, $root): void {
                $ids = collect($page->toArray()['props']['options']['members'])->pluck('id')->all();

                $this->assertEqualsCanonicalizing([$user->id, $eligible->id], $ids);
                $this->assertNotContains($withoutPosition->id, $ids);
                $this->assertNotContains($inactive->id, $ids);
                $this->assertNotContains($root->id, $ids);
            });
    }

    public function test_a_member_without_position_cannot_be_assigned_as_responsible(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $withoutPosition = User::factory()->operator()->forGabinete($office)->withoutPosition()->create();
        $demand = Demand::factory()->forGabinete($office, creator: $user)->create();

        $this->actingAs($user)
            ->post(route('demands.next-action.store', $demand), [
                'descricao' => 'Ligar',
                'data' => now()->toDateString(),
                'responsavel_id' => $withoutPosition->id,
            ])
            ->assertSessionHasErrors('responsavel_id');
    }

    public function test_today_and_overdue_tabs_ignore_resolved_and_closed_demands(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $make = fn (DemandStatus $status, string $date) => Demand::factory()->forGabinete($office, creator: $user)->create([
            'status' => $status,
            'proxima_acao_descricao' => 'Ação',
            'proxima_acao_data' => $date,
            'proxima_acao_concluida_em' => null,
        ]);
        $make(DemandStatus::New, now()->toDateString());
        $make(DemandStatus::Resolved, now()->toDateString());
        $make(DemandStatus::InProgress, now()->subDays(2)->toDateString());
        $make(DemandStatus::Closed, now()->subDays(2)->toDateString());

        $this->actingAs($user)
            ->get(route('demands.index', ['tab' => 'today']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('demands.data', 1)
                ->where('tabCounts.today', 1)
                ->where('tabCounts.overdue', 1));

        $this->get(route('demands.index', ['tab' => 'overdue']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('demands.data', 1));
    }
}
