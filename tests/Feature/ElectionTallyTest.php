<?php

namespace Tests\Feature;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\MunicipioEleitoral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ElectionTallyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_office_cannot_see_the_tally(): void
    {
        $user = User::factory()->create(['gabinete_id' => null]);

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertForbidden();
    }

    public function test_the_clock_window_uses_the_office_timezone_during_todays_round(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Manaus']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        // A busca da apuração não tem mais gate de horário (ver
        // ElectionTallyController::index()) — sem fake, sairia para a rede.
        Http::fake();

        // 11h em Manaus (UTC-4) é 15h em UTC — a janela devolvida precisa
        // refletir o fuso do gabinete, não o do servidor (UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-04 11:00:00', 'America/Manaus'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('politics/apuracao')
                ->where('round.election_id', $election->id)
                ->where('round.label', 'Eleições Gerais 2026 · 1º turno')
                ->where('round.date', '2026-10-04')
                ->where('round.is_today', true)
                ->where('round.window_start', '2026-10-04T08:00:00-04:00')
                ->where('round.window_end', '2026-10-04T17:00:00-04:00')
            );
    }

    public function test_the_second_round_is_picked_when_it_falls_today(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake();

        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('round.label', 'Eleições Gerais 2026 · 2º turno')
                ->where('round.date', '2026-10-25')
                ->where('round.is_today', true)
            );
    }

    public function test_the_nearest_future_round_is_offered_outside_election_day(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('round.label', 'Eleições Gerais 2026 · 1º turno')
                ->where('round.date', '2026-10-04')
                ->where('round.is_today', false)
            );
    }

    public function test_the_most_recent_past_round_is_offered_once_every_round_is_over(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        // Esse round já passou das 17h, então o controller tenta buscar a
        // apuração — sem fake, a requisição sairia para a rede de verdade.
        Http::fake(['resultados.tse.jus.br/*' => Http::response(null, 404)]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('round.label', 'Eleições Gerais 2026 · 2º turno')
                ->where('round.date', '2026-10-25')
                ->where('round.is_today', false)
            );
    }

    public function test_the_candidate_roster_shows_up_before_voting_closes(): void
    {
        // O TSE publica a lista de candidatos (com votos zerados) desde
        // antes das 17h — a tela mostra "quem está concorrendo" o dia
        // inteiro, não só a apuração em si.
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'ce' => ['3' => [['5', 'CANDIDATO CE', '0']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.1.cargo', 'governador')
                ->where('results.1.candidates.0.name', 'CANDIDATO CE')
                ->where('results.1.candidates.0.votes', 0)
                ->where('results.1.section_percent', 0)
            );
    }

    public function test_results_combine_the_national_race_with_the_offices_own_state(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'br' => ['1' => [['22', 'CANDIDATO BR', '0'], ['13', 'OUTRO BR', '0']]],
            'ce' => [
                '3' => [['5', 'CANDIDATO CE', '0']],
                '5' => [['7', 'SENADOR CE', '0']],
            ],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.0.cargo', 'presidente')
                ->where('results.0.available', true)
                ->where('results.0.candidates.0.name', 'CANDIDATO BR')
                ->where('results.1.cargo', 'governador')
                ->where('results.1.candidates.0.name', 'CANDIDATO CE')
            );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/br/br-c0001-'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ce/ce-c0003-'));
    }

    public function test_proportional_races_are_capped_at_ten_with_a_hidden_count(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $manyCandidates = collect(range(1, 15))
            ->map(fn (int $i): array => [(string) $i, "CANDIDATO {$i}", (string) (150 - $i)])
            ->all();
        Http::fake($this->tseFakes([
            'ce' => ['6' => $manyCandidates],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.3.cargo', 'deputado_federal')
                ->where('results.3.candidates.0.name', 'CANDIDATO 1')
                ->has('results.3.candidates', 5)
                ->where('results.3.total_candidates', 15)
                ->has('results.3.all_candidates', 15)
            );
    }

    public function test_a_tse_failure_on_one_race_does_not_break_the_others(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'br' => ['1' => null],
            'ce' => ['3' => [['5', 'CANDIDATO CE', '0']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.0.cargo', 'presidente')
                ->where('results.0.available', false)
                ->where('results.1.cargo', 'governador')
                ->where('results.1.available', true)
            );
    }

    public function test_a_favorited_candidate_shows_its_live_votes(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->stateCandidate($election, 'CE', 'Governador', '5', 'CANDIDATO DO GABINETE');
        $candidate->favoritos()->forceCreate(['gabinete_id' => $office->id]);
        Http::fake($this->tseFakes([
            'ce' => ['3' => [['5', 'CANDIDATO DO GABINETE', '900']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('favorites', 1)
                ->where('favorites.0.candidato_politico_id', $candidate->id)
                ->where('favorites.0.cargo_label', 'Governador')
                ->where('favorites.0.found', true)
                ->where('favorites.0.votes', 900)
            );
    }

    public function test_a_favorite_not_yet_counted_is_shown_without_votes(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        // Cadastrado com outro número do que o publicado pelo TSE para o
        // cargo — o cruzamento não encontra, mas não derruba a página.
        $candidate = $this->stateCandidate($election, 'CE', 'governador', '9', 'FORA DA LISTA');
        $candidate->favoritos()->forceCreate(['gabinete_id' => $office->id]);
        Http::fake($this->tseFakes([
            'ce' => ['3' => [['5', 'OUTRO CANDIDATO', '900']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('favorites.0.found', false)
                ->where('favorites.0.votes', null)
            );
    }

    public function test_favorites_from_another_election_are_not_mixed_in(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $otherElection = Eleicao::query()->where('ano', 2024)->firstOrFail();
        $municipalCandidate = $this->stateCandidate($otherElection, 'CE', 'Vereador', '5', 'VEREADOR 2024');
        $municipalCandidate->favoritos()->forceCreate(['gabinete_id' => $office->id]);
        Http::fake($this->tseFakes([]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page->has('favorites', 0));
    }

    public function test_a_favorite_shows_up_zeroed_before_voting_closes(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $candidate = $this->stateCandidate($election, 'CE', 'Governador', '5', 'CANDIDATO DO GABINETE');
        $candidate->favoritos()->forceCreate(['gabinete_id' => $office->id]);
        Http::fake($this->tseFakes([
            'ce' => ['3' => [['5', 'CANDIDATO DO GABINETE', '0']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('favorites.0.found', true)
                ->where('favorites.0.votes', 0)
            );
    }

    private function stateCandidate(Eleicao $election, string $uf, string $cargo, string $number, string $ballotName): CandidatoPolitico
    {
        return CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => $number,
            'abrangencia' => CandidateScope::State,
            'uf' => $uf,
            'cargo' => $cargo,
            'nome' => $ballotName,
            'nome_urna' => $ballotName,
            'numero' => $number,
            'partido_sigla' => 'ABC',
            'situacao' => 'APTO',
        ]);
    }

    public function test_seats_are_counted_per_party_for_proportional_races(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'ce' => ['6' => [['11', 'A', '900', true], ['12', 'B', '800', true], ['13', 'C', '700'], ['14', 'D', '600', true]]],
        ], true));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.3.seats.0.party', 'PX')
                ->where('results.3.seats.0.seats', 3)
                ->where('results.3.goes_to_second_round', false)
                ->where('results.3.chamber_total', 513)
                ->where('uf', 'CE')
            );
    }

    /**
     * Senado e Câmara são bancadas do Brasil inteiro, não só da UF do
     * gabinete — o gráfico soma as 27 UFs, mas ainda precisa saber quantas
     * daquelas cadeiras são do próprio estado, para o destaque visual.
     */
    public function test_nationwide_seats_sum_every_state_but_track_the_offices_own_uf(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'ce' => ['6' => [['11', 'A', '900', true], ['12', 'B', '800', true]]],
            'sp' => ['6' => [['21', 'C', '900', true], ['22', 'D', '800', true], ['23', 'E', '700', true]]],
        ], true));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.3.cargo', 'deputado_federal')
                ->where('results.3.seats.0.party', 'PX')
                ->where('results.3.seats.0.seats', 5)
                ->where('results.3.seats.0.home_seats', 2)
            );
    }

    public function test_a_first_round_without_winner_points_to_the_second_round(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'br' => ['1' => [['13', 'LULA', '500'], ['22', 'OUTRO', '400']]],
        ], true));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.0.cargo', 'presidente')
                ->where('results.0.goes_to_second_round', true)
                ->where('round.has_second_round', true)
                ->where('round.round', 1)
            );
    }

    /**
     * O TSE marca `e:"s"` nos dois candidatos classificados para o 2º turno
     * de um cargo majoritário, não só em quem venceu — reproduzido ao vivo
     * em 06/10/2026 na eleição de presidente (Bolsonaro 47,03% e Lula
     * 45,16%, ambos com `e:"s"`, section_percent 100%). Sem corrigir pela
     * maioria absoluta, o painel mostrava os dois como eleitos.
     */
    public function test_two_tse_qualified_candidates_without_majority_go_to_second_round_instead_of_both_elected(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'br' => ['1' => [
                ['22', 'BOLSONARO', '56104503', true, '47,03'],
                ['13', 'LULA', '53879538', true, '45,16'],
                ['70', 'CURY', '3448569', false, '2,89'],
            ]],
        ], true));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.0.cargo', 'presidente')
                ->where('results.0.goes_to_second_round', true)
                ->where('results.0.candidates.0.elected', false)
                ->where('results.0.candidates.1.elected', false)
            );
    }

    /**
     * Vitória de verdade em 1º turno (maioria absoluta) continua marcada
     * como eleito — a correção só desconta quem o TSE sinalizou sem
     * ultrapassar 50%.
     */
    public function test_a_first_round_majority_winner_is_still_marked_as_elected(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'ce' => ['3' => [
                ['99', 'ELMANO', '2500000', true, '53,19'],
                ['77', 'CIRO', '2100000', false, '46,22'],
            ]],
        ], true));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.1.cargo', 'governador')
                ->where('results.1.goes_to_second_round', false)
                ->where('results.1.candidates.0.elected', true)
            );
    }

    public function test_the_municipal_section_uses_the_offices_city_file(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '13692',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create(['estado' => 'CE', 'municipio' => 'Cruz', 'municipio_eleitoral_id' => $municipality->id]);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes(
            ['ce' => ['3' => [['5', 'CANDIDATO CE', '0']]]],
            true,
            ['3' => [['5', 'CANDIDATO DA CIDADE', '321']]],
        ));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('municipal.name', 'Cruz')
                ->where('municipal.results.1.cargo', 'governador')
                ->where('municipal.results.1.candidates.0.name', 'CANDIDATO DA CIDADE')
                ->where('municipal.results.1.candidates.0.votes', 321)
            );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/dados/ce/ce13692-c0003-e006259-u.json'));
    }

    /**
     * Quem venceu na cidade não é necessariamente quem a eleição elegeu —
     * presidente se decide na contagem nacional, governador na estadual. O
     * card do município mostra os votos locais, mas o selo de "eleito" vem
     * da apuração de verdade, não de quem tirou mais voto só ali.
     */
    public function test_the_municipal_section_marks_elected_from_the_statewide_result_not_the_local_vote_leader(): void
    {
        $municipality = MunicipioEleitoral::query()->create([
            'codigo_tse' => '13692',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $office = Gabinete::factory()->create(['estado' => 'CE', 'municipio' => 'Cruz', 'municipio_eleitoral_id' => $municipality->id]);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes(
            ['ce' => ['3' => [
                ['5', 'VENCEDOR ESTADUAL', '2000000', true, '60,00'],
                ['7', 'FAVORITO LOCAL', '1500000', false, '40,00'],
            ]]],
            true,
            ['3' => [
                ['7', 'FAVORITO LOCAL', '900', false, '70,00'],
                ['5', 'VENCEDOR ESTADUAL', '300', false, '30,00'],
            ]],
        ));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('municipal.results.1.cargo', 'governador')
                // Mais votado na cidade continua sendo quem a tela lista primeiro...
                ->where('municipal.results.1.candidates.0.name', 'FAVORITO LOCAL')
                // ...mas não é ele quem está marcado como eleito.
                ->where('municipal.results.1.candidates.0.elected', false)
                ->where('municipal.results.1.candidates.1.name', 'VENCEDOR ESTADUAL')
                ->where('municipal.results.1.candidates.1.elected', true)
            );
    }

    public function test_the_municipal_section_is_null_without_a_linked_city(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE', 'municipio_eleitoral_id' => null]);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes(['ce' => ['3' => [['5', 'CANDIDATO CE', '0']]]]));

        $this->travelTo(CarbonImmutable::parse('2026-10-04 18:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index'))
            ->assertInertia(fn (Assert $page) => $page->where('municipal', null));
    }

    /**
     * Monta as rotas fakes para o cliente da apuração: o índice de
     * configuração (sempre), mais um 404 de segurança para qualquer cargo
     * não listado explicitamente — assim um teste só precisa descrever os
     * cargos que lhe interessam.
     *
     * @param  array<string, array<string, array<int, array{0: string, 1: string, 2: string}>|null>>  $fakes
     *                                                                                                        Por escopo ("br" ou a UF), um mapa de código do cargo para a lista
     *                                                                                                        de candidatos `[numero, nome, votos]`, ou `null` para simular uma
     *                                                                                                        falha do TSE naquele cargo.
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $municipal  Votos da cidade (código 13692, CE), por cargo.
     */
    private function tseFakes(array $fakes, bool $finished = false, array $municipal = []): array
    {
        $cargoPaths = ['1' => '0001', '3' => '0003', '5' => '0005', '6' => '0006', '7' => '0007'];
        $turnoByCargo = ['1' => '6257', '3' => '6259', '5' => '6259', '6' => '6259', '7' => '6259'];

        $routes = [
            'resultados.tse.jus.br/oficial/comum/config/ele-c.json' => Http::response($this->eleicaoConfig()),
        ];

        foreach ($fakes as $scope => $byCargo) {
            foreach ($byCargo as $cargoCode => $items) {
                $turno = $turnoByCargo[$cargoCode];
                $path = "resultados.tse.jus.br/oficial/ele2026/{$turno}/dados/{$scope}/{$scope}-c{$cargoPaths[$cargoCode]}-e*";
                $routes[$path] = $items === null
                    ? Http::response(null, 500)
                    : Http::response($this->candidates($items, $cargoCode, $finished));
            }
        }

        // Arquivo do município: pasta da UF, nome com o código do município.
        foreach ($municipal as $cargoCode => $items) {
            $turno = $turnoByCargo[$cargoCode];
            $path = "resultados.tse.jus.br/oficial/ele2026/{$turno}/dados/ce/ce13692-c{$cargoPaths[$cargoCode]}-e*";
            $routes[$path] = Http::response($this->candidates($items, $cargoCode, $finished));
        }

        // Qualquer cargo não listado acima responde 404 — o mesmo que o TSE
        // devolve antes do arquivo existir.
        $routes['resultados.tse.jus.br/*'] = Http::response(null, 404);

        return $routes;
    }

    /**
     * Um candidato por item `[numero, nome, votos]`, no mesmo formato
     * (EA20/resultado unificado) confirmado ao vivo contra o TSE em
     * 04/10/2026.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $items
     */
    private function candidates(array $items, string $cargoCode, bool $finished = false): string
    {
        $cand = collect($items)->map(fn (array $item): array => [
            'n' => $item[0],
            'sqcand' => $item[0],
            'nm' => $item[1],
            'nmu' => $item[1],
            'vap' => $item[2],
            'pvap' => $item[4] ?? '0,00',
            'e' => ($item[3] ?? false) ? 's' : 'n',
        ])->all();

        return json_encode([
            's' => ['ts' => '23765', 'st' => '0', 'pst' => $finished ? '100,00' : '0,00'],
            'carg' => [[
                'cd' => $cargoCode,
                'agr' => [[
                    'com' => 'PARTIDO',
                    'par' => [['sg' => 'PX', 'cand' => $cand]],
                ]],
            ]],
        ]);
    }

    private function eleicaoConfig(): string
    {
        return json_encode([
            'pl' => [[
                'cd' => '3220',
                'c' => 'ele2026',
                'dt' => '04/10/2026',
                'e' => [
                    [
                        'cd' => '6257',
                        'cdt2' => '6258',
                        'nm' => 'Eleição Ordinária Federal - 2026 1º Turno',
                        'abr' => [['cd' => 'br', 'cp' => [['cd' => '1', 'ds' => 'Presidente']]]],
                    ],
                    [
                        'cd' => '6259',
                        'cdt2' => '6260',
                        'nm' => 'Eleição Ordinária Estadual - 2026 1º Turno',
                        'abr' => [['cd' => 'br', 'cp' => [
                            ['cd' => '3', 'ds' => 'Governador'],
                            ['cd' => '5', 'ds' => 'Senador'],
                            ['cd' => '6', 'ds' => 'Deputado Federal'],
                            ['cd' => '7', 'ds' => 'Deputado Estadual'],
                        ]]],
                    ],
                ],
            ]],
        ]);
    }

    public function test_the_countdown_moves_to_the_second_round_after_the_first(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('countdown.date', '2026-10-25')
                ->where('countdown.round_label', '2º turno')
            );
    }

    public function test_the_first_round_can_be_picked_after_it_has_passed(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'ce' => ['3' => [['5', 'CANDIDATO CE', '0']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index', ['data' => '2026-10-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('round.label', 'Eleições Gerais 2026 · 1º turno')
                ->where('round.date', '2026-10-04')
                ->has('rounds', 4)
            );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ele2026/6259/dados/ce/ce-c0003-e006259-u.json'));
    }

    public function test_the_second_round_fetches_the_second_round_turno_code(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->operator()->forGabinete($office)->create();
        Http::fake($this->tseFakes([
            'ce' => ['3' => [['5', 'CANDIDATO CE', '0']]],
        ]));

        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('politics.tally.index', ['data' => '2026-10-25']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('round.label', 'Eleições Gerais 2026 · 2º turno')
            );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ele2026/6260/dados/ce/ce-c0003-e006260-u.json'));
    }
}
