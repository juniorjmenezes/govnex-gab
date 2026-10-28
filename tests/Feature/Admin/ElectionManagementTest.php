<?php

namespace Tests\Feature\Admin;

use App\Enums\CandidateScope;
use App\Jobs\TallyElection;
use App\Models\Candidate;
use App\Models\CandidateElectionResult;
use App\Models\Election;
use App\Models\Office;
use App\Models\TseSync;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ElectionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_root_can_create_a_general_election(): void
    {
        $root = User::factory()->root()->create();

        // A migration do painel político já cadastra duas eleições-semente.
        $this->actingAs($root)
            ->get(route('admin.elections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/elections/index')
                ->has('elections', 2));

        $this->actingAs($root)
            ->post(route('admin.elections.store'), [
                'ano' => 2030,
                'tipo' => 'geral',
                'nome' => 'Eleições Gerais 2030',
                'primeiro_turno_em' => '2030-10-06',
                'segundo_turno_em' => '2030-10-27',
                'situacao' => 'programada',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('eleicoes', [
            'ano' => 2030,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2030',
        ]);
    }

    public function test_root_can_create_a_municipal_election(): void
    {
        $root = User::factory()->root()->create();

        $this->actingAs($root)
            ->post(route('admin.elections.store'), [
                'ano' => 2028,
                'tipo' => 'municipal',
                'nome' => 'Eleições Municipais 2028',
                'primeiro_turno_em' => '2028-10-01',
                'segundo_turno_em' => '2028-10-29',
                'situacao' => 'programada',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('eleicoes', [
            'ano' => 2028,
            'tipo' => 'municipal',
            'nome' => 'Eleições Municipais 2028',
        ]);
    }

    public function test_election_rejects_duplicate_year_and_type(): void
    {
        $root = User::factory()->root()->create();
        Election::query()->create([
            'ano' => 2030,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2030',
            'primeiro_turno_em' => '2030-10-06',
            'situacao' => 'programada',
        ]);

        $this->actingAs($root)
            ->post(route('admin.elections.store'), [
                'ano' => 2030,
                'tipo' => 'geral',
                'nome' => 'Duplicada',
                'primeiro_turno_em' => '2030-10-06',
                'situacao' => 'programada',
            ])
            ->assertSessionHasErrors('ano');
    }

    public function test_election_rejects_second_round_before_first_round(): void
    {
        $root = User::factory()->root()->create();

        $this->actingAs($root)
            ->post(route('admin.elections.store'), [
                'ano' => 2030,
                'tipo' => 'geral',
                'nome' => 'Eleições Gerais 2030',
                'primeiro_turno_em' => '2030-10-06',
                'segundo_turno_em' => '2030-09-01',
                'situacao' => 'programada',
            ])
            ->assertSessionHasErrors('segundo_turno_em');
    }

    public function test_root_can_update_an_existing_election(): void
    {
        $root = User::factory()->root()->create();
        $eleicao = Election::query()->create([
            'ano' => 2030,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2030',
            'primeiro_turno_em' => '2030-10-06',
            'situacao' => 'programada',
        ]);

        $this->actingAs($root)
            ->patch(route('admin.elections.update', $eleicao), [
                'codigo_tse' => '2030-geral',
                'ano' => 2030,
                'tipo' => 'geral',
                'nome' => 'Eleições Gerais 2030',
                'primeiro_turno_em' => '2030-10-06',
                'segundo_turno_em' => '2030-10-27',
                'situacao' => 'concluida',
            ])
            ->assertRedirect();

        $eleicao->refresh();
        $this->assertSame('2030-geral', $eleicao->codigo_tse);
        $this->assertSame('concluida', $eleicao->situacao);
        $this->assertSame('2030-10-27', $eleicao->segundo_turno_em->toDateString());
    }

    public function test_root_can_trigger_second_round_classification(): void
    {
        Queue::fake();

        $root = User::factory()->root()->create();
        $eleicao = Election::query()->create([
            'ano' => 2022,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2022',
            'primeiro_turno_em' => '2022-10-02',
            'segundo_turno_em' => '2022-10-30',
            'situacao' => 'concluida',
        ]);

        $this->actingAs($root)
            ->post(route('admin.elections.apurar', $eleicao))
            ->assertRedirect();

        Queue::assertPushed(
            TallyElection::class,
            fn (TallyElection $job): bool => $job->electionId === $eleicao->id,
        );
    }

    public function test_cannot_trigger_classification_before_first_round(): void
    {
        Queue::fake();

        $root = User::factory()->root()->create();
        $eleicao = Election::query()->create([
            'ano' => 2030,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2030',
            'primeiro_turno_em' => now()->addYear()->toDateString(),
            'situacao' => 'programada',
        ]);

        $this->actingAs($root)
            ->post(route('admin.elections.apurar', $eleicao))
            ->assertRedirect();

        Queue::assertNotPushed(TallyElection::class);
    }

    public function test_non_root_users_cannot_trigger_classification(): void
    {
        Queue::fake();

        $gabinete = Office::factory()->create();
        $councilor = User::factory()->administrator()->forGabinete($gabinete)->create();
        $eleicao = Election::query()->create([
            'ano' => 2022,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2022',
            'primeiro_turno_em' => '2022-10-02',
            'situacao' => 'concluida',
        ]);

        $this->actingAs($councilor)
            ->post(route('admin.elections.apurar', $eleicao))
            ->assertForbidden();

        Queue::assertNotPushed(TallyElection::class);
    }

    public function test_creating_an_election_leads_to_its_lifecycle_page(): void
    {
        $root = User::factory()->root()->create();

        $response = $this->actingAs($root)->post(route('admin.elections.store'), [
            'ano' => 2030,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2030',
            'primeiro_turno_em' => '2030-10-06',
            'situacao' => 'programada',
        ]);

        $response->assertRedirect(route('admin.elections.show', Election::query()->where('ano', 2030)->firstOrFail()));
    }

    /** Eleição geral: sem votação nominal, locais nem seções; com registro de pesquisas. */
    public function test_the_general_election_page_lists_only_the_general_datasets(): void
    {
        $root = User::factory()->root()->create();
        Office::factory()->create();
        $election = Election::query()->where('ano', 2026)->firstOrFail();

        $this->actingAs($root)
            ->get(route('admin.elections.show', $election))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/elections/show')
                ->where('election.id', $election->id)
                ->where('politicsAvailable', true)
                ->where('datasets', fn ($datasets): bool => collect($datasets)->pluck('key')->all() === [
                    'municipalities', 'electorate', 'candidates', 'turnout', 'poll_registry',
                ])
                ->where('datasets.0.requires_year', false)
                ->where('datasets.0.slug', 'municipio-tse-ibge')
                ->where('datasets.1.slug', 'perfil-eleitorado-2026-{uf}')
                ->where('tally.available', true));
    }

    /** Eleição municipal: com votação nominal, locais e seções; sem registro de pesquisas. */
    public function test_the_municipal_election_page_lists_only_the_municipal_datasets(): void
    {
        $root = User::factory()->root()->create();
        $election = Election::query()->where('ano', 2024)->firstOrFail();

        $this->actingAs($root)
            ->get(route('admin.elections.show', $election))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('datasets', fn ($datasets): bool => collect($datasets)->pluck('key')->all() === [
                    'municipalities', 'electorate', 'candidates', 'turnout',
                    'candidate_votes', 'polling_locations', 'section_votes',
                ])
                ->where('datasets.6.slug', 'votacao-secao-2024-{uf}'));
    }

    /** Cada linha traz a última execução do dataset no ano da eleição — não a de outro ano. */
    public function test_the_election_page_shows_the_latest_run_of_each_dataset_for_its_year(): void
    {
        $root = User::factory()->root()->create();
        $election = Election::query()->where('ano', 2024)->firstOrFail();
        $sync = fn (string $dataset, int $year, string $status) => TseSync::query()->create([
            'gabinete_id' => null,
            'dataset' => $dataset,
            'ano' => $year,
            'fonte_url' => 'https://example.test/govnex-api',
            'situacao' => $status,
            'iniciada_em' => now(),
        ]);
        $sync('polling_locations', 2024, 'falhou');
        $sync('candidates', 2026, 'concluida');
        $sync('municipalities', 2026, 'concluida');

        $this->actingAs($root)
            ->get(route('admin.elections.show', $election))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('datasets', function ($datasets): bool {
                    $byKey = collect($datasets)->keyBy('key');

                    return $byKey['polling_locations']['latest_sync']['status'] === 'falhou'
                        // Candidaturas de 2026 não são as de 2024.
                        && $byKey['candidates']['latest_sync'] === null
                        // A base de municípios não tem ano: vale a última.
                        && $byKey['municipalities']['latest_sync']['status'] === 'concluida';
                }));
    }

    /** A apuração mostra, por cargo, quantas candidaturas já têm resultado gravado. */
    public function test_the_election_page_summarizes_the_tally_by_office(): void
    {
        $root = User::factory()->root()->create();
        $election = Election::query()->where('ano', 2026)->firstOrFail();
        $candidate = fn (string $number, string $office) => Candidate::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => "9{$number}",
            'abrangencia' => CandidateScope::State,
            'uf' => 'CE',
            'cargo' => $office,
            'nome' => "Candidato {$number}",
            'nome_urna' => "Nome {$number}",
            'numero' => $number,
            'partido_sigla' => 'ABC',
            'situacao' => 'APTO',
        ]);
        $elected = $candidate('451', 'Senador');
        $candidate('452', 'Senador');
        $candidate('1111', 'Deputado Federal');
        CandidateElectionResult::query()->create([
            'candidato_politico_id' => $elected->id,
            'eleicao_id' => $election->id,
            'turno' => 1,
            'votos' => 100,
            'percentual_validos' => 60,
            'situacao_totalizacao' => CandidateElectionResult::ELECTED,
            'eleito' => true,
        ]);

        $this->actingAs($root)
            ->get(route('admin.elections.show', $election))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tally.offices', 2)
                ->where('tally.offices.0.office', 'SENADOR')
                ->where('tally.offices.0.source', 'tse')
                ->where('tally.offices.0.candidates', 2)
                ->where('tally.offices.0.with_result', 1)
                ->where('tally.offices.0.elected', 1)
                ->where('tally.offices.1.office', 'DEPUTADO FEDERAL')
                ->where('tally.offices.1.with_result', 0)
                ->where('tally.offices.1.updated_at', null));
    }

    public function test_non_root_users_cannot_access_election_management(): void
    {
        $gabinete = Office::factory()->create();
        $councilor = User::factory()->administrator()->forGabinete($gabinete)->create();
        $eleicao = Election::query()->create([
            'ano' => 2030,
            'tipo' => 'geral',
            'nome' => 'Eleições Gerais 2030',
            'primeiro_turno_em' => '2030-10-06',
            'situacao' => 'programada',
        ]);

        $this->actingAs($councilor)->get(route('admin.elections.index'))->assertForbidden();
        $this->actingAs($councilor)->get(route('admin.elections.show', $eleicao))->assertForbidden();
        $this->actingAs($councilor)->post(route('admin.elections.store'), [
            'ano' => 2032,
            'tipo' => 'geral',
            'nome' => 'Outra eleição',
            'primeiro_turno_em' => '2032-10-03',
            'situacao' => 'programada',
        ])->assertForbidden();
        $this->actingAs($councilor)
            ->patch(route('admin.elections.update', $eleicao), [
                'ano' => 2030,
                'tipo' => 'geral',
                'nome' => 'Eleições Gerais 2030',
                'primeiro_turno_em' => '2030-10-06',
                'situacao' => 'concluida',
            ])
            ->assertForbidden();
    }
}
