<?php

namespace Tests\Feature;

use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\ResultadoPesquisaEleitoral;
use App\Services\Politics\Polls\PesquisaResultProvider;
use App\Services\Politics\Polls\ResultadoColeta;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ResultResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_persist_writes_results_and_records_provenance_when_nothing_persisted_yet(): void
    {
        $pesquisa = $this->pesquisa();
        $resultado = new ResultadoColeta(
            provider: 'teste',
            tipo: 'portal',
            confidenceScore: 60,
            candidatos: [[
                'external_candidate_id' => 'cand-1',
                'candidato_politico_id' => null,
                'nome' => 'Fulano',
                'partido' => 'ABC',
                'percentual' => 42.5,
            ]],
            url: 'https://exemplo.test/materia',
        );

        $fonte = (new ResultResolver)->persist($pesquisa, $resultado);

        $this->assertSame('coletado', $fonte->status);
        $this->assertSame('teste', $fonte->provider);
        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'external_candidate_id' => 'cand-1',
            'percentual' => 42.5,
        ]);
        $this->assertSame(60, $pesquisa->fresh()->confianca);
        $this->assertSame('teste', $pesquisa->fresh()->origem_provider);
    }

    public function test_persist_never_lets_a_lower_confidence_source_overwrite_a_better_one(): void
    {
        $pesquisa = $this->pesquisa(['confianca' => 80, 'origem_provider' => 'oficial']);
        $this->createResultado($pesquisa, 'cand-1', 'Fulano', 55.0);

        $resolver = new ResultResolver;
        $fonte = $resolver->persist($pesquisa, new ResultadoColeta(
            provider: 'electiolab',
            tipo: 'electiolab',
            confidenceScore: 60,
            candidatos: [[
                'external_candidate_id' => 'cand-1',
                'candidato_politico_id' => null,
                'nome' => 'Fulano',
                'partido' => 'ABC',
                'percentual' => 10.0,
            ]],
        ));

        // A tentativa fica registrada (auditoria)...
        $this->assertSame('electiolab', $fonte->provider);
        $this->assertDatabaseHas('pesquisa_fontes', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'provider' => 'electiolab',
        ]);
        // ...mas o resultado de maior confiança já persistido não é alterado.
        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'external_candidate_id' => 'cand-1',
            'percentual' => 55.0,
        ]);
        $this->assertSame(80, $pesquisa->fresh()->confianca);
        $this->assertSame('oficial', $pesquisa->fresh()->origem_provider);
    }

    public function test_persist_accepts_a_source_with_equal_or_higher_confidence(): void
    {
        $pesquisa = $this->pesquisa(['confianca' => 60, 'origem_provider' => 'electiolab']);
        $this->createResultado($pesquisa, 'cand-1', 'Fulano', 40.0);

        (new ResultResolver)->persist($pesquisa, new ResultadoColeta(
            provider: 'portal-confiavel',
            tipo: 'portal',
            confidenceScore: 70,
            candidatos: [[
                'external_candidate_id' => 'cand-1',
                'candidato_politico_id' => null,
                'nome' => 'Fulano',
                'partido' => 'ABC',
                'percentual' => 44.0,
            ]],
        ));

        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'external_candidate_id' => 'cand-1',
            'percentual' => 44.0,
        ]);
        $this->assertSame(70, $pesquisa->fresh()->confianca);
    }

    public function test_resolve_returns_the_first_supporting_provider_with_a_result(): void
    {
        $pesquisa = $this->pesquisa();
        $resultadoFinal = new ResultadoColeta(
            provider: 'segundo',
            tipo: 'portal',
            confidenceScore: 70,
            candidatos: [],
        );

        $indisponivel = new class implements PesquisaResultProvider
        {
            public function supports(PesquisaEleitoral $pesquisa): bool
            {
                return false;
            }

            public function buscar(PesquisaEleitoral $pesquisa): ?ResultadoColeta
            {
                throw new RuntimeException('Não deveria ser chamado.');
            }
        };
        $semResultado = new class implements PesquisaResultProvider
        {
            public function supports(PesquisaEleitoral $pesquisa): bool
            {
                return true;
            }

            public function buscar(PesquisaEleitoral $pesquisa): ?ResultadoColeta
            {
                return null;
            }
        };
        $comResultado = new class($resultadoFinal) implements PesquisaResultProvider
        {
            public function __construct(private readonly ResultadoColeta $resultado) {}

            public function supports(PesquisaEleitoral $pesquisa): bool
            {
                return true;
            }

            public function buscar(PesquisaEleitoral $pesquisa): ?ResultadoColeta
            {
                return $this->resultado;
            }
        };

        $resolver = new ResultResolver([$indisponivel, $semResultado, $comResultado]);

        $this->assertSame($resultadoFinal, $resolver->resolve($pesquisa));
    }

    private function pesquisa(array $overrides = []): PesquisaEleitoral
    {
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();

        return PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => (string) Str::uuid(),
            'external_election_id' => (string) Str::uuid(),
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Teste',
            'publicada_em' => today(),
            'fonte_url' => 'https://exemplo.test',
            ...$overrides,
        ]);
    }

    private function createResultado(PesquisaEleitoral $pesquisa, string $externalCandidateId, string $nome, float $percentual): void
    {
        ResultadoPesquisaEleitoral::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'external_candidate_id' => $externalCandidateId,
            'candidato_nome' => $nome,
            'percentual' => $percentual,
        ]);
    }
}
