<?php

namespace Tests\Feature;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\ResultadoCandidatoEleicao;
use App\Models\User;
use App\Services\Politics\ElectionLifecycleService;
use App\Services\Politics\ElectionResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Resultado gravado dos cargos que se decidem no 1º turno sem conceito de 2º
 * turno — senador (majoritário simples) e deputados (proporcional) —, para a
 * coluna "Situação" do painel político deixar de ficar vazia para eles.
 */
class ElectionResultRecordingTest extends TestCase
{
    use RefreshDatabase;

    private Eleicao $election;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00'));
        // Eleição-semente: 1º turno em 04/10/2026, 2º em 25/10/2026.
        $this->election = Eleicao::query()->where('ano', 2026)->firstOrFail();
    }

    // ---- Senador ---------------------------------------------------------

    /** Duas cadeiras em 2026: vale a marcação do TSE, não a maioria absoluta. */
    public function test_senate_race_records_the_tse_flagged_winners_without_a_runoff(): void
    {
        $senators = $this->candidates('Senador', ['451', '452', '453']);

        $recorded = app(ElectionResultRecorder::class)->record(
            $this->election,
            'senador',
            'CE',
            1,
            $this->tseResult(100, [
                ['451', 4_000, 40.0, true, 'Eleito'],
                ['452', 3_500, 35.0, true, 'Eleito'],
                ['453', 2_500, 25.0, false, 'Não eleito'],
            ]),
        );

        $this->assertTrue($recorded);
        $this->assertResult($senators['451'], 'ELEITO', true, 4_000);
        $this->assertResult($senators['452'], 'ELEITO', true, 3_500);
        $this->assertResult($senators['453'], 'NÃO ELEITO', false, 2_500);
        // Sem 2º turno para senador: nada a classificar nem a gravar depois.
        $this->assertNull($senators['451']->refresh()->avancou_segundo_turno);
        $this->assertFalse(app(ElectionResultRecorder::class)->record($this->election, 'senador', 'CE', 2));
    }

    public function test_senate_race_is_not_recorded_while_counting_or_before_the_tse_flags_the_winners(): void
    {
        $this->candidates('Senador', ['451', '452']);
        $recorder = app(ElectionResultRecorder::class);

        $this->assertFalse($recorder->record($this->election, 'senador', 'CE', 1, $this->tseResult(90, [
            ['451', 4_000, 55.0, true, null],
            ['452', 3_000, 45.0, false, null],
        ])));
        // 100% apurado, mas sem marcação: a totalização não fechou.
        $this->assertFalse($recorder->record($this->election, 'senador', 'CE', 1, $this->tseResult(100, [
            ['451', 4_000, 55.0, false, null],
            ['452', 3_000, 45.0, false, null],
        ])));

        $this->assertSame(0, ResultadoCandidatoEleicao::query()->count());
    }

    // ---- Deputados -------------------------------------------------------

    /**
     * Proporcional: quem entra é quem ocupa as cadeiras do partido, não quem
     * tem mais votos — o 2211 é mais votado que o 1122 e fica de fora.
     */
    public function test_proportional_race_records_who_took_the_partys_seats(): void
    {
        $deputies = $this->candidates('Deputado Federal', ['1111', '1122', '1133', '2211', '2222']);

        $recorded = app(ElectionResultRecorder::class)->record(
            $this->election,
            'deputado_federal',
            'CE',
            1,
            $this->tseResult(100, [
                ['1111', 90_000, 9.0, true, 'Eleito por QP'],
                ['2211', 40_000, 4.0, false, 'Suplente'],
                ['1122', 30_000, 3.0, true, 'Eleito por média'],
                ['1133', 20_000, 2.0, false, 'Suplente'],
                // Feed sem situação publicada: cai em eleito/não eleito.
                ['2222', 10_000, 1.0, false, null],
            ]),
        );

        $this->assertTrue($recorded);
        $this->assertResult($deputies['1111'], 'ELEITO POR QP', true, 90_000);
        $this->assertResult($deputies['1122'], 'ELEITO POR MÉDIA', true, 30_000);
        $this->assertResult($deputies['2211'], 'SUPLENTE', false, 40_000);
        $this->assertResult($deputies['1133'], 'SUPLENTE', false, 20_000);
        $this->assertResult($deputies['2222'], 'NÃO ELEITO', false, 10_000);
    }

    /** Situação publicada que contradiz a marcação `e` não é usada. */
    public function test_proportional_status_follows_the_tse_flag_when_the_published_text_disagrees(): void
    {
        $deputies = $this->candidates('Deputado Estadual', ['12345', '23456']);

        app(ElectionResultRecorder::class)->record(
            $this->election,
            'deputado_estadual',
            'CE',
            1,
            $this->tseResult(100, [
                ['12345', 50_000, 50.0, true, 'Suplente'],
                ['23456', 40_000, 40.0, false, 'Eleito por QP'],
            ]),
        );

        $this->assertResult($deputies['12345'], 'ELEITO', true, 50_000);
        $this->assertResult($deputies['23456'], 'NÃO ELEITO', false, 40_000);
    }

    // ---- Ciclo inteiro ---------------------------------------------------

    /**
     * O comando agendado apura todos os cargos do feed, e o painel político
     * passa a mostrar a situação de senador e deputado na coluna "Situação".
     */
    public function test_tallying_the_election_records_every_office_and_feeds_the_situation_column(): void
    {
        $office = Gabinete::factory()->create(['estado' => 'CE']);
        $user = User::factory()->administrator()->forGabinete($office)->create();
        $this->candidates('Senador', ['451', '452']);
        $this->candidates('Deputado Federal', ['1111', '2211']);

        Http::fake([
            'https://resultados.tse.jus.br/oficial/comum/config/ele-c.json' => Http::response([
                'pl' => [[
                    'dt' => '04/10/2026',
                    'c' => 'ele2026',
                    'e' => [['cd' => '6259', 'cdt2' => '6265', 'abr' => [['cp' => [['cd' => '5'], ['cd' => '6']]]]]],
                ]],
            ]),
            'https://resultados.tse.jus.br/oficial/ele2026/6259/dados/ce/ce-c0005-e006259-u.json' => Http::response(
                $this->feed('5', [
                    ['451', '600', '60,00', 's', 'Eleito'],
                    ['452', '400', '40,00', 'n', 'Não eleito'],
                ]),
            ),
            'https://resultados.tse.jus.br/oficial/ele2026/6259/dados/ce/ce-c0006-e006259-u.json' => Http::response(
                $this->feed('6', [
                    ['1111', '900', '90,00', 's', 'Eleito por QP'],
                    ['2211', '100', '10,00', 'n', 'Suplente'],
                ]),
            ),
        ]);

        $this->artisan('politics:tally-elections')->assertSuccessful();

        $this->actingAs($user)
            ->get(route('politics.index', ['eleicao_id' => $this->election->id, 'mostrar_primeiro_turno' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data', function ($candidates): bool {
                    $byNumber = collect($candidates)->keyBy('numero');

                    return $byNumber['451']['resultado']['situacao'] === 'ELEITO'
                        && $byNumber['451']['resultado']['eleito'] === true
                        && $byNumber['452']['resultado']['situacao'] === 'NÃO ELEITO'
                        && $byNumber['1111']['resultado']['situacao'] === 'ELEITO POR QP'
                        && $byNumber['1111']['resultado']['eleito'] === true
                        && $byNumber['2211']['resultado']['situacao'] === 'SUPLENTE'
                        && $byNumber['2211']['resultado']['eleito'] === false;
                }));

        // Repetir não volta ao TSE: as disputas já estão gravadas.
        $sent = Http::recorded()->count();
        $this->assertSame(['recorded' => 0, 'classified' => 0], app(ElectionLifecycleService::class)->tally($this->election));
        $this->assertSame($sent, Http::recorded()->count());
    }

    /** A tela de apuração ao vivo aproveita a leitura que já fez do feed. */
    public function test_the_live_tally_records_the_seat_offices_it_already_read(): void
    {
        $senators = $this->candidates('Senador', ['451', '452']);
        Http::preventStrayRequests();

        app(ElectionLifecycleService::class)->recordLiveTally($this->election, 'CE', 1, [
            'senador' => $this->tseResult(100, [
                ['451', 600, 60.0, true, 'Eleito'],
                ['452', 400, 40.0, false, 'Não eleito'],
            ]),
            'deputado_federal' => null,
        ]);

        $this->assertResult($senators['451'], 'ELEITO', true, 600);
        $this->assertResult($senators['452'], 'NÃO ELEITO', false, 400);
    }

    // ---- Apoio -----------------------------------------------------------

    /**
     * @param  list<string>  $numbers
     * @return array<string, CandidatoPolitico>
     */
    private function candidates(string $office, array $numbers): array
    {
        $candidates = [];

        foreach ($numbers as $number) {
            $candidates[$number] = CandidatoPolitico::query()->create([
                'eleicao_id' => $this->election->id,
                'sq_candidato' => "9{$number}",
                'abrangencia' => CandidateScope::State,
                'uf' => 'CE',
                'cargo' => $office,
                'nome' => "Candidato {$number}",
                'nome_urna' => "Nome {$number}",
                'numero' => $number,
                'partido_sigla' => str_starts_with($number, '2') ? 'XYZ' : 'ABC',
                'situacao' => 'APTO',
            ]);
        }

        return $candidates;
    }

    private function assertResult(CandidatoPolitico $candidate, string $status, bool $elected, int $votes): void
    {
        $result = ResultadoCandidatoEleicao::query()
            ->where('candidato_politico_id', $candidate->id)
            ->where('turno', 1)
            ->first();

        $this->assertNotNull($result, "Sem resultado para {$candidate->numero}");
        $this->assertSame($status, $result->situacao_totalizacao);
        $this->assertSame($elected, $result->eleito);
        $this->assertSame($votes, $result->votos);
        $this->assertSame($this->election->id, $result->eleicao_id);
    }

    /**
     * Formato normalizado de TseResultsClient::results().
     *
     * @param  list<array{0: string, 1: int, 2: float, 3: bool, 4: string|null}>  $candidates  número, votos, %, `e:"s"`, `st`
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
                'votes' => $candidate[1],
                'vote_percent' => $candidate[2],
                'elected' => $candidate[3],
                'status' => $candidate[4],
            ], $candidates),
        ];
    }

    /**
     * Arquivo cru do feed do TSE (EA20) de um cargo, 100% apurado.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $candidates  n, vap, pvap, e, st
     * @return array<string, mixed>
     */
    private function feed(string $cargoCode, array $candidates): array
    {
        return [
            's' => ['pst' => '100,00', 'ts' => '10', 'st' => '10'],
            'carg' => [[
                'cd' => $cargoCode,
                'agr' => [[
                    'com' => null,
                    'par' => [[
                        'sg' => 'ABC',
                        'cand' => array_map(fn (array $candidate): array => [
                            'n' => $candidate[0],
                            'nm' => "Candidato {$candidate[0]}",
                            'nmu' => "Nome {$candidate[0]}",
                            'vap' => $candidate[1],
                            'pvap' => $candidate[2],
                            'e' => $candidate[3],
                            'st' => $candidate[4],
                        ], $candidates),
                    ]],
                ]],
            ]],
        ];
    }
}
