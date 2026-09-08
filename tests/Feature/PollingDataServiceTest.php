<?php

namespace Tests\Feature;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\ResultadoPesquisaEleitoral;
use App\Services\Politics\Polls\ElectionContext;
use App\Services\Politics\Polls\PollingDataService;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollingDataServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O fingerprint fica em cache entre execuções de propósito — sem
        // limpar, um teste anterior poderia fazer este pular o
        // processamento por engano.
        Cache::flush();
    }

    /**
     * @param  list<array<string, mixed>>  $tabPesquisas
     * @return array<string, mixed>
     */
    private function fixturePayload(array $tabPesquisas, array $registrosMeta = [], array $overrides = []): array
    {
        return array_merge([
            'candidates' => [],
            'tabPesquisas' => $tabPesquisas,
            'institutos' => [],
            'registrosMeta' => $registrosMeta,
            'candsIn' => [],
            'free' => 1,
            'cruzamento' => null,
            'lastUpdate' => now()->toIso8601String(),
            '_fingerprint' => 123456,
            'cached' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function noDataPayload(): array
    {
        return ['candidates' => [], 'lastUpdate' => now()->toIso8601String(), 'noData' => true];
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'registro' => 'CE-00001/2026',
            'instituto' => 'Instituto Teste <> Telefonica (CATI)',
            'data' => '2026-07-20',
            'dataArquivamento' => '2026-07-21',
            'origem' => 'RELATORIOS',
            'url' => 'https://example.com/pesquisa',
            'modo' => 'Telefonica (CATI)',
            'entrevistas' => 2000,
            'cenarioNome' => 'Intenção de voto estimulada - 1º turno',
            'cenarioId' => 1,
            'partido' => 'PT',
            'voto' => 40.5,
            'nv' => null,
            'candidato' => 'Fulano (PT)',
            'erro' => '2,0%',
            'confianca' => '95%',
        ], $overrides);
    }

    /**
     * Faz o Http::fake() responder de acordo com o parâmetro `url=` da
     * querystring — cada ElectionContext monta uma URL diferente e é assim
     * que o endpoint real distingue os contextos.
     *
     * @param  array<string, mixed>  $responsesByContextUrl  chave = ElectionContext::sourceUrl(), valor = resposta (array de payload, ou Illuminate\Http\Client\Response via Http::response())
     */
    private function fakeContexts(array $responsesByContextUrl): void
    {
        Http::fake(function (Request $request) use ($responsesByContextUrl) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requestedUrl = $query['url'] ?? null;

            if ($requestedUrl !== null && array_key_exists($requestedUrl, $responsesByContextUrl)) {
                $response = $responsesByContextUrl[$requestedUrl];

                return is_array($response) ? Http::response($response) : $response;
            }

            return Http::response(['error' => 'contexto inesperado no teste', 'requested_url' => $requestedUrl], 500);
        });
    }

    private function seedElection(int $year = 2026): Eleicao
    {
        return Eleicao::query()->where('ano', $year)->firstOrFail();
    }

    public function test_election_context_builds_the_expected_source_urls(): void
    {
        $this->assertSame(
            'https://www.pollingdata.com.br/2026/presidente/br/t1_todas',
            ElectionContext::presidenteNacional(2026)->sourceUrl(),
        );
        $this->assertSame(
            'https://www.pollingdata.com.br/2026/presidente/ce/t1',
            ElectionContext::presidenteEstadual(2026, 'ce')->sourceUrl(),
        );
        $this->assertSame(
            'https://www.pollingdata.com.br/2026/governador/ce/t1',
            ElectionContext::governador(2026, 'ce')->sourceUrl(),
        );
        $this->assertSame(
            'https://www.pollingdata.com.br/2026/senador/ce/t1',
            ElectionContext::senador(2026, 'ce')->sourceUrl(),
        );
        $this->assertSame('nacional', ElectionContext::presidenteNacional(2026)->abrangencia());
        $this->assertSame('estadual', ElectionContext::governador(2026, 'CE')->abrangencia());
    }

    public function test_default_contexts_cover_presidente_nacional_and_27_ufs_for_governador_and_senador(): void
    {
        $contexts = app(PollingDataService::class)->defaultContexts(2026);

        $this->assertCount(1 + 27 + 27, $contexts);
        $this->assertSame('presidente', $contexts[0]->office);
        $this->assertSame('BR', $contexts[0]->uf);

        $governadorUfs = collect($contexts)->where('office', 'governador')->pluck('uf')->all();
        $senadorUfs = collect($contexts)->where('office', 'senador')->pluck('uf')->all();
        $this->assertCount(27, $governadorUfs);
        $this->assertCount(27, $senadorUfs);
        $this->assertContains('CE', $governadorUfs);
        $this->assertContains('CE', $senadorUfs);
        // O catálogo real do PollingData tem uma linha "Senador/BR" que é um
        // erro de tag da própria fonte (Senado não tem corrida nacional) —
        // confirmamos que BR nunca entra na lista de UFs sincronizadas.
        $this->assertNotContains('BR', $senadorUfs);
    }

    public function test_it_populates_cargo_uf_turno_ano_from_the_context_not_from_the_payload(): void
    {
        $election = $this->seedElection();
        CandidatoPolitico::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => 'gov-ce-1',
            'abrangencia' => CandidateScope::State,
            'uf' => 'CE',
            'cargo' => 'Governador',
            'nome' => 'Fulano de Tal',
            'nome_urna' => 'Fulano',
            'partido_sigla' => 'PT',
        ]);
        $context = ElectionContext::governador(2026, 'CE');
        $this->fakeContexts([
            $context->sourceUrl() => $this->fixturePayload([$this->row()]),
        ]);

        app(PollingDataService::class)->syncContext($context, app(ResultResolver::class));

        $poll = PesquisaEleitoral::query()->where('registro_tse', 'CE-00001/2026')->firstOrFail();
        $this->assertSame('governador', $poll->cargo);
        $this->assertSame('CE', $poll->uf);
        $this->assertSame(1, $poll->turno);
        $this->assertSame(2026, $poll->ano);
        $this->assertSame('estadual', $poll->abrangencia);
        $this->assertSame('Fulano', $poll->resultados()->first()?->candidato_nome);
    }

    public function test_it_treats_no_data_as_a_non_fatal_empty_result(): void
    {
        $context = ElectionContext::governador(2026, 'AC');
        $this->fakeContexts([$context->sourceUrl() => $this->noDataPayload()]);

        $processed = app(PollingDataService::class)->syncContext($context, app(ResultResolver::class));

        $this->assertSame(0, $processed);
        $this->assertDatabaseCount('pesquisas_eleitorais', 0);
    }

    public function test_it_treats_404_as_a_non_fatal_empty_result(): void
    {
        $context = ElectionContext::senador(2026, 'RR');
        $this->fakeContexts([$context->sourceUrl() => Http::response('', 404)]);

        $processed = app(PollingDataService::class)->syncContext($context, app(ResultResolver::class));

        $this->assertSame(0, $processed);
    }

    public function test_identity_includes_cargo_so_the_same_registro_and_cenario_id_do_not_collide_across_offices(): void
    {
        // Auditoria real confirmou isso: CE-04292/2026 tem cenarioId=1 tanto
        // pra Governador quanto pra Senador — questionários diferentes, não
        // relacionados, que coincidem em registro+cenarioId.
        $election = $this->seedElection();
        $governadorContext = ElectionContext::governador(2026, 'CE');
        $senadorContext = ElectionContext::senador(2026, 'CE');
        $sharedRegistro = 'CE-04292/2026';

        $this->fakeContexts([
            $governadorContext->sourceUrl() => $this->fixturePayload([
                $this->row([
                    'registro' => $sharedRegistro,
                    'cenarioId' => 1,
                    'candidato' => 'Candidato Governador (PT)',
                    'cenarioNome' => 'Governo do Ceará - 1º turno',
                ]),
            ]),
            $senadorContext->sourceUrl() => $this->fixturePayload([
                $this->row([
                    'registro' => $sharedRegistro,
                    'cenarioId' => 1,
                    'candidato' => 'Candidato Senador (PT)',
                    'cenarioNome' => 'Intenção de voto para Senador',
                ]),
            ]),
        ]);

        $resolver = app(ResultResolver::class);
        $service = app(PollingDataService::class);
        $service->syncContext($governadorContext, $resolver);
        $service->syncContext($senadorContext, $resolver);

        $polls = PesquisaEleitoral::query()->where('registro_tse', $sharedRegistro)->get();
        $this->assertCount(2, $polls, 'Governador e Senador com o mesmo registro+cenarioId devem gerar pesquisas separadas.');
        $this->assertNotSame($polls[0]->external_id, $polls[1]->external_id);
        $this->assertEqualsCanonicalizing(
            ['governador', 'senador'],
            $polls->pluck('cargo')->all(),
        );

        $governadorPoll = $polls->firstWhere('cargo', 'governador');
        $senadorPoll = $polls->firstWhere('cargo', 'senador');
        $this->assertSame('Candidato Governador', $governadorPoll->resultados()->first()?->candidato_nome);
        $this->assertSame('Candidato Senador', $senadorPoll->resultados()->first()?->candidato_nome);
    }

    public function test_sync_many_continues_after_one_context_fails(): void
    {
        $ok = ElectionContext::governador(2026, 'CE');
        $broken = ElectionContext::governador(2026, 'SP');

        Http::fake(function (Request $request) use ($ok, $broken) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['url'] ?? null) === $ok->sourceUrl()) {
                return Http::response($this->fixturePayload([$this->row(['registro' => 'CE-00002/2026'])]));
            }

            if (($query['url'] ?? null) === $broken->sourceUrl()) {
                return Http::response('Access Denied', 403);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $results = app(PollingDataService::class)->syncMany([$ok, $broken], app(ResultResolver::class));

        $this->assertSame(2, $results[$ok->label()]);
        $this->assertNull($results[$broken->label()]);
        $this->assertDatabaseHas('pesquisas_eleitorais', ['registro_tse' => 'CE-00002/2026']);
    }

    public function test_a_permanent_error_is_not_retried_but_a_transient_one_is(): void
    {
        $permanent = ElectionContext::governador(2026, 'CE');
        $transient = ElectionContext::senador(2026, 'CE');
        $transientAttempts = 0;

        Http::fake(function (Request $request) use ($permanent, $transient, &$transientAttempts) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['url'] ?? null) === $permanent->sourceUrl()) {
                return Http::response('Access Denied', 403);
            }

            if (($query['url'] ?? null) === $transient->sourceUrl()) {
                $transientAttempts++;

                return $transientAttempts < 2
                    ? Http::response('', 500)
                    : Http::response($this->fixturePayload([$this->row(['registro' => 'CE-00003/2026'])]));
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $service = app(PollingDataService::class);
        $resolver = app(ResultResolver::class);

        try {
            $service->syncContext($permanent, $resolver);
            $this->fail('Esperava falha para o contexto com 403.');
        } catch (\Throwable) {
            // esperado
        }

        Http::assertSentCount(1);

        $processed = $service->syncContext($transient, $resolver);
        $this->assertSame(2, $processed);
        $this->assertSame(2, $transientAttempts, 'Erro 5xx deveria ter sido re-tentado até suceder.');
    }

    public function test_running_the_sync_twice_does_not_duplicate_records(): void
    {
        $context = ElectionContext::governador(2026, 'CE');
        $this->fakeContexts([
            $context->sourceUrl() => $this->fixturePayload([
                $this->row(['candidato' => 'Fulano (PT)', 'voto' => 40.5]),
                $this->row(['candidato' => 'Ciclano (PL)', 'partido' => 'PL', 'voto' => 35.2]),
            ]),
        ]);

        $service = app(PollingDataService::class);
        $resolver = app(ResultResolver::class);

        $service->syncContext($context, $resolver);
        $pollCount = PesquisaEleitoral::query()->count();
        $resultCount = ResultadoPesquisaEleitoral::query()->count();

        // Fingerprint idêntico faria a segunda chamada pular tudo — o que já
        // é coberto por outro teste — aqui simulamos um fingerprint novo
        // pra garantir que o upsert em si também é idempotente.
        Cache::flush();
        $service->syncContext($context, $resolver);

        $this->assertSame($pollCount, PesquisaEleitoral::query()->count());
        $this->assertSame($resultCount, ResultadoPesquisaEleitoral::query()->count());
    }

    public function test_it_skips_processing_when_the_fingerprint_is_unchanged(): void
    {
        $context = ElectionContext::governador(2026, 'CE');
        $this->fakeContexts([
            $context->sourceUrl() => $this->fixturePayload([$this->row()], overrides: ['_fingerprint' => 999]),
        ]);

        $service = app(PollingDataService::class);
        $resolver = app(ResultResolver::class);

        $first = $service->syncContext($context, $resolver);
        $this->assertGreaterThan(0, $first);

        $second = $service->syncContext($context, $resolver);
        $this->assertSame(0, $second, 'Fingerprint igual ao anterior deveria pular o processamento.');
    }

    public function test_buscar_reconstructs_the_context_from_the_persisted_pesquisa(): void
    {
        $election = $this->seedElection();
        $context = ElectionContext::senador(2026, 'CE');
        $this->fakeContexts([
            $context->sourceUrl() => $this->fixturePayload([$this->row(['registro' => 'CE-00009/2026'])]),
        ]);
        $resolver = app(ResultResolver::class);
        $service = app(PollingDataService::class);
        $service->syncContext($context, $resolver);

        $pesquisa = PesquisaEleitoral::query()->where('registro_tse', 'CE-00009/2026')->firstOrFail();

        $resultado = $service->buscar($pesquisa);

        $this->assertNotNull($resultado);
        $this->assertSame('pollingdata', $resultado->provider);
        $this->assertCount(1, $resultado->candidatos);
    }

    public function test_supports_accepts_the_three_covered_offices_and_rejects_others(): void
    {
        $election = $this->seedElection();
        $service = app(PollingDataService::class);

        foreach (['presidente', 'governador', 'senador'] as $cargo) {
            $pesquisa = new PesquisaEleitoral(['cargo' => $cargo]);
            $this->assertTrue($service->supports($pesquisa), "esperava suportar {$cargo}");
        }

        $this->assertFalse($service->supports(new PesquisaEleitoral(['cargo' => 'prefeito'])));
    }
}
