<?php

namespace Tests\Feature;

use App\Enums\CandidateScope;
use App\Models\Candidate;
use App\Models\CandidateElectionResult;
use App\Models\CandidateMunicipalityVote;
use App\Models\Election;
use App\Models\ElectoralMunicipality;
use App\Models\ElectoralPoll;
use App\Models\ElectoralPollAverage;
use App\Models\ElectoralPollResult;
use App\Models\Office;
use App\Models\User;
use App\Services\Politics\ElectionResultRecorder;
use App\Services\Politics\SecondRoundClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A mesma regra para eleição geral (governador, pelo TSE ao vivo) e municipal
 * (prefeito, pela votação nominal sincronizada), nos mesmos três cenários:
 * decidida no 1º turno, classificada para o 2º turno e ainda em apuração.
 */
class SecondRoundClassificationTest extends TestCase
{
    use RefreshDatabase;

    // ---- Eleição geral (governador) -------------------------------------

    public function test_general_race_decided_in_the_first_round_hides_everyone_once_the_first_round_date_has_passed(): void
    {
        [$election, $user, $governors] = $this->generalScenario();

        $classified = app(SecondRoundClassifier::class)->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(100, [['11', 55.0, true], ['22', 30.0, false], ['33', 15.0, false]]),
        );

        $this->assertFalse($classified);
        $this->assertSame(0, Candidate::query()->whereNotNull('avancou_segundo_turno')->count());

        // Vitória decidida já no 1º turno não grava `avancou_segundo_turno`
        // (não houve 2º turno a classificar) — então, passado o dia do 1º
        // turno, ninguém dessa eleição continua na lista geral, nem o
        // vencedor nem o senador (cargo sem 2º turno): o escopo visível
        // depois dessa data é só "quem vai ao 2º turno". O mesmo corte vale
        // pros botões de pesquisa: sem nenhuma disputa classificada pro 2º
        // turno (nem presidente, nem governador do CE), nenhum cargo é
        // oferecido.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 0)
                ->where('polls.available', false)
                ->has('polls.offices', 0));
    }

    public function test_general_race_classified_for_the_runoff_shows_only_finalists_and_runoff_polls(): void
    {
        [$election, $user, $governors, $senator] = $this->generalScenario();

        $classified = app(SecondRoundClassifier::class)->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(100, [['11', 45.0, true], ['22', 35.0, true], ['33', 20.0, false]]),
        );

        $this->assertTrue($classified);
        $this->assertTrue($governors['11']->refresh()->avancou_segundo_turno);
        $this->assertTrue($governors['22']->refresh()->avancou_segundo_turno);
        $this->assertFalse($governors['33']->refresh()->avancou_segundo_turno);
        // Senado nunca tem 2º turno: fica intocado.
        $this->assertNull($senator->refresh()->avancou_segundo_turno);

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Passado o dia do 1º turno, a lista geral só mostra quem
                // avançou de verdade para o 2º turno — o senador (cargo sem
                // 2º turno, `avancou_segundo_turno` nulo) some da lista,
                // mesmo fora da disputa de governador.
                ->has('candidates.data', 2)
                ->where('candidates.data', fn ($candidates) => collect($candidates)
                    ->pluck('numero')
                    ->sort()
                    ->values()
                    ->all() === ['11', '22'])
                // Senador (nunca tem 2º turno) e presidente (sem disputa
                // classificada neste cenário) saem da lista de cargos —
                // só o governador classificado continua.
                ->has('polls.offices', 1)
                ->where('polls.offices.0.slug', 'governador')
                ->where('polls.offices.0.round', 2)
                ->has('polls.offices.0.polls', 1)
                ->where('polls.offices.0.polls.0.institute', 'Instituto 2º turno')
                // A média do ElectioLab agrega o 1º turno: some no 2º.
                ->where('polls.offices.0.averages', [])
                ->where('polls.offices.0.has_aggregate', false));

        // Um favorito eliminado ainda pode ser removido dos favoritos.
        $this->actingAs($user)
            ->delete(route('politics.favorites.destroy', $governors['33']))
            ->assertRedirect();
    }

    public function test_general_race_classified_for_the_runoff_can_show_the_full_first_round_when_requested(): void
    {
        [$election, $user, $governors, $senator] = $this->generalScenario();

        app(SecondRoundClassifier::class)->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(100, [['11', 45.0, true], ['22', 35.0, true], ['33', 20.0, false]]),
        );

        // Toggle desligado (padrão): só finalistas; o senador (cargo sem 2º
        // turno) já saiu de cena porque o 1º turno passou. O painel sabe que
        // há uma disputa decidida para o 2º turno para oferecer o toggle.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 2)
                ->where('filters.mostrar_primeiro_turno', false)
                ->where('hasSecondRoundDecided', true));

        // Toggle ligado ("Mostrar 1º turno"): devolve o universo inteiro do
        // 1º turno, incluindo o eliminado de governador e o senador — cargo
        // sem 2º turno que não é eliminado nem classificado, só nunca teve
        // esse corte.
        $this->actingAs($user)
            ->get(route('politics.index', [
                'eleicao_id' => $election->id,
                'mostrar_primeiro_turno' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 4)
                ->where('candidates.data', fn ($candidates) => collect($candidates)
                    ->pluck('numero')
                    ->sort()
                    ->values()
                    ->all() === collect(array_keys($governors))
                    ->map(fn (int|string $number): string => (string) $number)
                    ->push($senator->numero)
                    ->sort()
                    ->values()
                    ->all())
                ->where('filters.mostrar_primeiro_turno', true));
    }

    public function test_general_race_decided_in_the_first_round_has_no_second_round_dispute_to_toggle(): void
    {
        [$election, $user] = $this->generalScenario();

        app(SecondRoundClassifier::class)->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(100, [['11', 55.0, true], ['22', 30.0, false], ['33', 15.0, false]]),
        );

        // Decidido no 1º turno, nada é gravado — passado o dia do 1º turno,
        // a lista geral também esvazia, então não há disputa de 2º turno a
        // oferecer no toggle.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 0)
                ->where('hasSecondRoundDecided', false));

        // Ainda assim, o toggle "Mostrar 1º turno" (quando acionado via URL)
        // devolve o universo inteiro, mesmo sem 2º turno decidido.
        $this->actingAs($user)
            ->get(route('politics.index', [
                'eleicao_id' => $election->id,
                'mostrar_primeiro_turno' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 4));
    }

    public function test_general_race_still_being_counted_is_not_classified(): void
    {
        [$election, $user] = $this->generalScenario();

        $classified = app(SecondRoundClassifier::class)->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(80, [['11', 45.0, false], ['22', 35.0, false], ['33', 20.0, false]]),
        );

        $this->assertFalse($classified);

        // Ainda sem classificação gravada e já passado o dia do 1º turno: a
        // lista geral some até alguém ser classificado — e, pelo mesmo
        // motivo, nenhum cargo é oferecido na seção de pesquisas.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 0)
                ->has('polls.offices', 0));
    }

    public function test_the_scheduled_command_classifies_governor_races_from_the_live_tse_feed(): void
    {
        [$election, , $governors] = $this->generalScenario();

        Http::fake([
            'https://resultados.tse.jus.br/oficial/comum/config/ele-c.json' => Http::response([
                'pl' => [[
                    'dt' => '04/10/2026',
                    'c' => 'ele2026',
                    'e' => [['cd' => '6259', 'cdt2' => '6265', 'abr' => [['cp' => [['cd' => '3']]]]]],
                ]],
            ]),
            'https://resultados.tse.jus.br/oficial/ele2026/6259/dados/ce/ce-c0003-e006259-u.json' => Http::response([
                's' => ['pst' => '100,00', 'ts' => '10', 'st' => '10'],
                'carg' => [[
                    'cd' => '3',
                    'agr' => [[
                        'com' => null,
                        'par' => [[
                            'sg' => 'ABC',
                            'cand' => [
                                ['n' => '11', 'nm' => 'A', 'nmu' => 'A', 'vap' => '450', 'pvap' => '45,00', 'e' => 's'],
                                ['n' => '22', 'nm' => 'B', 'nmu' => 'B', 'vap' => '350', 'pvap' => '35,00', 'e' => 's'],
                                ['n' => '33', 'nm' => 'C', 'nmu' => 'C', 'vap' => '200', 'pvap' => '20,00', 'e' => 'n'],
                            ],
                        ]],
                    ]],
                ]],
            ]),
        ]);

        $this->artisan('politics:tally-elections')->assertSuccessful();

        $this->assertTrue($governors['11']->refresh()->avancou_segundo_turno);
        $this->assertTrue($governors['22']->refresh()->avancou_segundo_turno);
        $this->assertFalse($governors['33']->refresh()->avancou_segundo_turno);
        // A mesma leitura do TSE grava o resultado do 1º turno.
        $this->assertResult($governors['11'], 1, CandidateElectionResult::SECOND_ROUND, 450, 45.0, false);
        $this->assertResult($governors['33'], 1, CandidateElectionResult::NOT_ELECTED, 200, 20.0, false);
    }

    // ---- Resultado gravado (presidente/governador) ----------------------

    public function test_general_race_decided_in_the_first_round_records_the_winner_without_a_runoff(): void
    {
        [$election, $user, $governors] = $this->generalScenario();
        $classifier = app(SecondRoundClassifier::class);
        $recorder = app(ElectionResultRecorder::class);

        $classifier->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(100, [['11', 55.0, true], ['22', 30.0, false], ['33', 15.0, false]]),
        );

        $this->assertResult($governors['11'], 1, CandidateElectionResult::ELECTED, 550, 55.0, true);
        $this->assertResult($governors['22'], 1, CandidateElectionResult::NOT_ELECTED, 300, 30.0, false);
        $this->assertResult($governors['33'], 1, CandidateElectionResult::NOT_ELECTED, 150, 15.0, false);
        $this->assertNull($governors['11']->refresh()->avancou_segundo_turno);

        // Sem classificados, não há 2º turno a gravar — nem leitura do TSE.
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00:00'));
        $this->assertFalse($recorder->record($election, 'governador', 'CE', 2));
        $this->assertSame(0, CandidateElectionResult::query()->where('turno', 2)->count());

        // Já gravado: o 1º turno não volta ao TSE.
        $this->assertFalse($recorder->record($election, 'governador', 'CE', 1));

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id, 'mostrar_primeiro_turno' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data', fn ($candidates) => collect($candidates)
                    // `==`: o JSON da resposta devolve 55.0 como 55.
                    ->firstWhere('numero', '11')['resultado'] == [
                        'situacao' => 'ELEITO',
                        'turno' => 1,
                        'votos' => 550,
                        'percentual' => 55.0,
                        'eleito' => true,
                    ])
                // O senador não tem resultado gravado: segue no status da candidatura.
                ->where('candidates.data', fn ($candidates) => collect($candidates)
                    ->firstWhere('numero', '51')['resultado'] === null));
    }

    public function test_general_race_with_a_runoff_records_the_first_round_and_then_the_final_result(): void
    {
        [$election, $user, $governors] = $this->generalScenario();
        $classifier = app(SecondRoundClassifier::class);
        $recorder = app(ElectionResultRecorder::class);

        $classifier->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(100, [['11', 45.0, true], ['22', 35.0, true], ['33', 20.0, false]]),
        );

        $this->assertResult($governors['11'], 1, CandidateElectionResult::SECOND_ROUND, 450, 45.0, false);
        $this->assertResult($governors['22'], 1, CandidateElectionResult::SECOND_ROUND, 350, 35.0, false);
        $this->assertResult($governors['33'], 1, CandidateElectionResult::NOT_ELECTED, 200, 20.0, false);
        $this->assertTrue($governors['11']->refresh()->avancou_segundo_turno);

        // Antes do dia do 2º turno, nada a gravar.
        $this->assertFalse($recorder->record(
            $election,
            'governador',
            'CE',
            2,
            $this->tseResult(100, [['22', 52.0, true], ['11', 48.0, false]]),
        ));

        $this->travelTo(CarbonImmutable::parse('2026-10-25 21:00:00'));

        // 2º turno ainda em apuração: nada gravado.
        $this->assertFalse($recorder->record(
            $election,
            'governador',
            'CE',
            2,
            $this->tseResult(90, [['22', 51.0, false], ['11', 49.0, false]]),
        ));

        $this->assertTrue($recorder->record(
            $election,
            'governador',
            'CE',
            2,
            $this->tseResult(100, [['22', 52.0, true], ['11', 48.0, false]]),
        ));

        $this->assertResult($governors['22'], 2, CandidateElectionResult::ELECTED, 520, 52.0, true);
        $this->assertResult($governors['11'], 2, CandidateElectionResult::NOT_ELECTED, 480, 48.0, false);
        // O 1º turno continua gravado como estava.
        $this->assertResult($governors['22'], 1, CandidateElectionResult::SECOND_ROUND, 350, 35.0, false);

        // O painel mostra o turno mais recente de cada um.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id, 'mostrar_primeiro_turno' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data', function ($candidates): bool {
                    $byNumber = collect($candidates)->keyBy('numero');

                    return $byNumber['22']['resultado']['situacao'] === 'ELEITO'
                        && $byNumber['22']['resultado']['turno'] === 2
                        && $byNumber['11']['resultado']['situacao'] === 'NÃO ELEITO'
                        && $byNumber['11']['resultado']['turno'] === 2
                        && $byNumber['33']['resultado']['situacao'] === 'NÃO ELEITO'
                        && $byNumber['33']['resultado']['turno'] === 1;
                }));
    }

    public function test_general_race_still_being_counted_records_no_result(): void
    {
        [$election] = $this->generalScenario();

        app(SecondRoundClassifier::class)->classify(
            $election,
            'governador',
            'CE',
            null,
            $this->tseResult(80, [['11', 55.0, false], ['22', 30.0, false], ['33', 15.0, false]]),
        );

        $this->assertSame(0, CandidateElectionResult::query()->count());
    }

    public function test_municipal_result_also_feeds_the_situation_column(): void
    {
        [$election, $user, $mayors] = $this->municipalScenario();
        $this->vote($election, $mayors['15'], 1, 'ELEITO', 6_000);
        $this->vote($election, $mayors['45'], 1, 'NÃO ELEITO', 3_000);

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id, 'mostrar_primeiro_turno' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data', fn ($candidates) => collect($candidates)
                    ->firstWhere('numero', '15')['resultado'] === [
                        'situacao' => 'ELEITO',
                        'turno' => 1,
                        'votos' => 6_000,
                        'percentual' => null,
                        'eleito' => true,
                    ]));
    }

    // ---- Eleição municipal (prefeito) -----------------------------------

    public function test_municipal_race_decided_in_the_first_round_hides_everyone_once_the_first_round_date_has_passed(): void
    {
        [$election, $user, $mayors] = $this->municipalScenario();
        $this->vote($election, $mayors['15'], 1, 'ELEITO', 6_000);
        $this->vote($election, $mayors['45'], 1, 'NÃO ELEITO', 3_000);
        $this->vote($election, $mayors['77'], 1, 'NÃO ELEITO', 1_000);

        // Eleito já no 1º turno não grava `avancou_segundo_turno` — passado
        // o dia do 1º turno, a lista geral some, vereador incluído.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 0)
                // Apurada e sem 2º turno: a pesquisa perde a função.
                ->where('polls', null));

        $this->assertSame(0, Candidate::query()->whereNotNull('avancou_segundo_turno')->count());
    }

    public function test_municipal_race_classified_for_the_runoff_shows_only_finalists_and_runoff_polls(): void
    {
        [$election, $user, $mayors, $councilor] = $this->municipalScenario();
        $this->vote($election, $mayors['15'], 1, '2º TURNO', 4_500);
        $this->vote($election, $mayors['45'], 1, '2º TURNO', 3_500);
        $this->vote($election, $mayors['77'], 1, 'NÃO ELEITO', 2_000);

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Vereador (cargo sem 2º turno, `avancou_segundo_turno` nulo)
                // some da lista geral junto com o prefeito eliminado.
                ->has('candidates.data', 2)
                ->where('candidates.data', fn ($candidates) => collect($candidates)
                    ->pluck('numero')
                    ->sort()
                    ->values()
                    ->all() === ['15', '45'])
                // Com o 2º turno de prefeito por vir, a pesquisa volta — a de 2º turno.
                ->has('polls.offices', 1)
                ->where('polls.offices.0.slug', 'prefeito')
                ->where('polls.offices.0.round', 2)
                ->has('polls.offices.0.polls', 1)
                ->where('polls.offices.0.polls.0.institute', 'Instituto 2º turno'));

        $this->assertTrue($mayors['15']->refresh()->avancou_segundo_turno);
        $this->assertTrue($mayors['45']->refresh()->avancou_segundo_turno);
        $this->assertFalse($mayors['77']->refresh()->avancou_segundo_turno);
        // Vereador nunca tem 2º turno.
        $this->assertNull($councilor->refresh()->avancou_segundo_turno);

        // Um eliminado ainda favoritado antes do corte continua podendo ser
        // desfavoritado, mesmo escondido da lista geral.
        $mayors['77']->favoritos()->forceCreate([
            'gabinete_id' => $user->gabinete_id,
            'escolhido_por_id' => $user->id,
        ]);
        $this->actingAs($user)
            ->delete(route('politics.favorites.destroy', $mayors['77']))
            ->assertRedirect();

        // E o vereador (cargo sem 2º turno, oculto da lista geral) continua
        // podendo ser desfavoritado também.
        $councilor->favoritos()->forceCreate([
            'gabinete_id' => $user->gabinete_id,
            'escolhido_por_id' => $user->id,
        ]);
        $this->actingAs($user)
            ->delete(route('politics.favorites.destroy', $councilor))
            ->assertRedirect();
    }

    public function test_municipal_race_still_being_counted_is_not_classified(): void
    {
        [$election, $user] = $this->municipalScenario();

        // Sem votação nominal sincronizada ainda, e já passado o dia do 1º
        // turno: não há o que decidir, então a lista geral some.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 0)
                ->where('polls', null));

        $this->assertSame(0, Candidate::query()->whereNotNull('avancou_segundo_turno')->count());
    }

    public function test_candidates_remain_visible_before_the_first_round_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));
        $election = Election::query()->where('ano', 2026)->firstOrFail();
        $office = Office::factory()->create(['estado' => 'CE']);
        $user = User::factory()->administrator()->forGabinete($office)->create();

        foreach (['11', '22', '33'] as $number) {
            $this->candidate($election, $number, CandidateScope::State, null, 'Governador');
        }

        $this->candidate($election, '51', CandidateScope::State, null, 'Senador');

        // Antes do dia do 1º turno, `avancou_segundo_turno` é sempre nulo e
        // nada é filtrado: a lista geral mostra todo mundo, exatamente como
        // antes dessa regra existir.
        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $election->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 4)
                ->where('hasSecondRoundDecided', false));
    }

    // ---- Cenários -------------------------------------------------------

    /**
     * Governador do CE com três candidatos, um senador, e pesquisas de
     * governador de 1º e 2º turno, com uma média do ElectioLab.
     *
     * @return array{0: Election, 1: User, 2: array<string, Candidate>, 3: Candidate}
     */
    private function generalScenario(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00'));
        $election = Election::query()->where('ano', 2026)->firstOrFail();
        $office = Office::factory()->create(['estado' => 'CE']);
        $user = User::factory()->administrator()->forGabinete($office)->create();
        $governors = [];

        foreach (['11', '22', '33'] as $number) {
            $governors[$number] = $this->candidate($election, $number, CandidateScope::State, null, 'Governador');
        }

        $senator = $this->candidate($election, '51', CandidateScope::State, null, 'Senador');
        $governors['33']->favoritos()->forceCreate([
            'gabinete_id' => $office->id,
            'escolhido_por_id' => $user->id,
        ]);

        $this->poll($election, 'governador', 1, 'Instituto 1º turno', null, $governors['11']);
        $this->poll($election, 'governador', 2, 'Instituto 2º turno', null, $governors['11']);
        ElectoralPollAverage::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => 'average-1',
            'external_election_id' => 'election-1',
            'external_candidate_id' => 'candidate-11',
            'candidato_politico_id' => $governors['11']->id,
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'candidato_nome' => 'Nome 11',
            'partido_sigla' => 'ABC',
            'media_ponderada' => 44.0,
            'pesquisas_incluidas' => 4,
            'amostra_total' => 4000,
            'fonte_url' => 'https://electiolab.test/api/v1/averages',
        ]);

        return [$election, $user, $governors, $senator];
    }

    /**
     * Cruz/CE em 2024, entre o 1º e o 2º turno: três candidatos a prefeito,
     * um a vereador e pesquisas de prefeito de 1º e 2º turno.
     *
     * @return array{0: Election, 1: User, 2: array<string, Candidate>, 3: Candidate}
     */
    private function municipalScenario(): array
    {
        $this->travelTo(CarbonImmutable::parse('2024-10-15 10:00:00'));
        $municipality = ElectoralMunicipality::query()->create([
            'codigo_tse' => '15890',
            'nome' => 'Cruz',
            'uf' => 'CE',
        ]);
        $election = Election::query()->where('ano', 2024)->firstOrFail();
        $office = Office::factory()->create([
            'estado' => 'CE',
            'municipio' => 'Cruz',
            'municipio_eleitoral_id' => $municipality->id,
        ]);
        $user = User::factory()->administrator()->forGabinete($office)->create();
        $mayors = [];

        foreach (['15', '45', '77'] as $number) {
            $mayors[$number] = $this->candidate($election, $number, CandidateScope::Municipal, $municipality->id, 'Prefeito');
        }

        $councilor = $this->candidate($election, '55123', CandidateScope::Municipal, $municipality->id, 'Vereador');

        $this->poll($election, 'prefeito', 1, 'Instituto 1º turno', 'Cruz', $mayors['15']);
        $this->poll($election, 'prefeito', 2, 'Instituto 2º turno', 'Cruz', $mayors['15']);

        return [$election, $user, $mayors, $councilor];
    }

    private function candidate(
        Election $election,
        string $number,
        CandidateScope $scope,
        ?int $municipalityId,
        string $office,
    ): Candidate {
        return Candidate::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => "9{$number}",
            'abrangencia' => $scope,
            'municipio_eleitoral_id' => $municipalityId,
            'uf' => 'CE',
            'cargo' => $office,
            'nome' => "Candidato {$number}",
            'nome_urna' => "Nome {$number}",
            'numero' => $number,
            'partido_sigla' => 'ABC',
            'situacao' => 'APTO',
        ]);
    }

    private function poll(
        Election $election,
        string $office,
        int $round,
        string $institute,
        ?string $city,
        Candidate $candidate,
    ): void {
        $poll = ElectoralPoll::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => "poll-{$office}-{$round}",
            'external_election_id' => 'election-1',
            'ano' => $election->ano,
            'uf' => 'CE',
            'municipio' => $city,
            'cargo' => $office,
            'turno' => $round,
            'instituto' => $institute,
            'publicada_em' => today()->subDays($round === 2 ? 1 : 20),
            'tamanho_amostra' => 1000,
            'fonte_url' => 'https://electiolab.test/api/v1/polls',
        ]);
        ElectoralPollResult::query()->create([
            'pesquisa_eleitoral_id' => $poll->id,
            'external_candidate_id' => "candidate-{$candidate->numero}",
            'candidato_politico_id' => $candidate->id,
            'candidato_nome' => $candidate->nome_urna,
            'partido_sigla' => 'ABC',
            'percentual' => 40.0,
        ]);
    }

    private function vote(Election $election, Candidate $candidate, int $round, string $status, int $votes): void
    {
        CandidateMunicipalityVote::query()->create([
            'candidato_politico_id' => $candidate->id,
            'municipio_eleitoral_id' => $candidate->municipio_eleitoral_id,
            'eleicao_id' => $election->id,
            'codigo_eleicao_tse' => '619',
            'ano' => $election->ano,
            'turno' => $round,
            'data_eleicao' => '2024-10-06',
            'votos_nominais' => $votes,
            'votos_nominais_validos' => $votes,
            'situacao_totalizacao' => $status,
            'eleito' => str_starts_with($status, 'ELEITO'),
            'fonte_url' => 'https://dadosabertos.tse.jus.br/',
        ]);
    }

    private function assertResult(
        Candidate $candidate,
        int $round,
        string $status,
        int $votes,
        float $percent,
        bool $elected,
    ): void {
        $result = CandidateElectionResult::query()
            ->where('candidato_politico_id', $candidate->id)
            ->where('turno', $round)
            ->first();

        $this->assertNotNull($result, "Sem resultado do {$round}º turno para {$candidate->numero}");
        $this->assertSame($status, $result->situacao_totalizacao);
        $this->assertSame($votes, $result->votos);
        $this->assertSame($percent, (float) $result->percentual_validos);
        $this->assertSame($elected, $result->eleito);
        $this->assertSame($candidate->eleicao_id, $result->eleicao_id);
    }

    /**
     * Resultado no formato normalizado de TseResultsClient::results().
     *
     * @param  list<array{0: string, 1: float, 2: bool}>  $candidates  número, % de votos, `e:"s"`
     * @return array<string, mixed>
     */
    private function tseResult(float $sectionPercent, array $candidates): array
    {
        return [
            'section_percent' => $sectionPercent,
            'total_sections' => 10,
            'counted_sections' => (int) round($sectionPercent / 10),
            'candidates' => array_map(fn (array $candidate): array => [
                'number' => $candidate[0],
                'name' => "Candidato {$candidate[0]}",
                'ballot_name' => "Nome {$candidate[0]}",
                'party' => 'ABC',
                'coalition' => null,
                'votes' => (int) ($candidate[1] * 10),
                'vote_percent' => $candidate[1],
                'elected' => $candidate[2],
            ], $candidates),
        ];
    }
}
