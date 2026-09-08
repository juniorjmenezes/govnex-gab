<?php

namespace Tests\Feature;

use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\PesquisaFonte;
use App\Services\Politics\Polls\ManualProvider;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_always_supports_any_pesquisa(): void
    {
        $this->assertTrue((new ManualProvider)->supports($this->pesquisa()));
    }

    public function test_it_returns_null_when_there_is_no_manual_entry(): void
    {
        $this->assertNull((new ManualProvider)->buscar($this->pesquisa()));
    }

    public function test_it_reads_a_previously_registered_manual_entry(): void
    {
        $pesquisa = $this->pesquisa();
        PesquisaFonte::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => 'manual',
            'provider' => 'manual',
            'status' => 'coletado',
            'confidence_score' => 50,
            'coletado_em' => now(),
            'metadata' => [
                'candidatos' => [[
                    'external_candidate_id' => 'cand-1',
                    'candidato_politico_id' => null,
                    'nome' => 'Fulano',
                    'partido' => 'ABC',
                    'percentual' => 33.3,
                ]],
            ],
        ]);

        $resultado = (new ManualProvider)->buscar($pesquisa);

        $this->assertNotNull($resultado);
        $this->assertSame('manual', $resultado->provider);
        $this->assertSame(50, $resultado->confidenceScore);
        $this->assertSame(33.3, $resultado->candidatos[0]['percentual']);
    }

    public function test_it_ignores_manual_entries_that_are_not_marked_as_coletado(): void
    {
        $pesquisa = $this->pesquisa();
        PesquisaFonte::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => 'manual',
            'provider' => 'manual',
            'status' => 'pendente',
            'confidence_score' => 50,
            'metadata' => ['candidatos' => [['external_candidate_id' => 'cand-1', 'candidato_politico_id' => null, 'nome' => 'Fulano', 'partido' => null, 'percentual' => 10.0]]],
        ]);

        $this->assertNull((new ManualProvider)->buscar($pesquisa));
    }

    public function test_it_is_usable_through_the_result_resolver(): void
    {
        $pesquisa = $this->pesquisa();
        PesquisaFonte::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => 'manual',
            'provider' => 'manual',
            'status' => 'coletado',
            'confidence_score' => 50,
            'coletado_em' => now(),
            'metadata' => [
                'candidatos' => [[
                    'external_candidate_id' => 'cand-1',
                    'candidato_politico_id' => null,
                    'nome' => 'Fulano',
                    'partido' => 'ABC',
                    'percentual' => 33.3,
                ]],
            ],
        ]);

        $resolver = new ResultResolver([new ManualProvider]);
        $resultado = $resolver->resolve($pesquisa);
        $this->assertNotNull($resultado);
        $resolver->persist($pesquisa, $resultado);

        $this->assertDatabaseHas('resultados_pesquisas_eleitorais', [
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'external_candidate_id' => 'cand-1',
            'percentual' => 33.3,
        ]);
    }

    private function pesquisa(): PesquisaEleitoral
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
        ]);
    }
}
