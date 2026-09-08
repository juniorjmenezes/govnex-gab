<?php

namespace Tests\Feature;

use App\Models\Eleicao;
use App\Models\PesquisaEleitoral;
use App\Models\ResultadoPesquisaEleitoral;
use App\Services\Politics\Polls\MediaCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_weighs_larger_samples_more_heavily(): void
    {
        // Amostra grande (2500 -> peso 50) puxa a média mais para perto de si
        // do que a pequena (400 -> peso 20), mas não a domina sozinha.
        $this->poll(sampleSize: 2500, publishedDaysAgo: 5, percentual: 50.0);
        $this->poll(sampleSize: 400, publishedDaysAgo: 5, percentual: 30.0);

        $medias = $this->calcular();

        $this->assertCount(1, $medias);
        $this->assertEqualsWithDelta(44.29, $medias[0]['media'], 0.01);
        $this->assertSame(2, $medias[0]['pesquisas_incluidas']);
    }

    public function test_it_ignores_polls_outside_the_lookback_window(): void
    {
        $this->poll(sampleSize: 1000, publishedDaysAgo: 5, percentual: 40.0);
        $this->poll(sampleSize: 1000, publishedDaysAgo: 400, percentual: 90.0);

        $medias = $this->calcular();

        $this->assertCount(1, $medias);
        $this->assertSame(40.0, $medias[0]['media']);
        $this->assertSame(1, $medias[0]['pesquisas_incluidas']);
    }

    public function test_it_discards_outliers_before_averaging(): void
    {
        $this->poll(sampleSize: 1000, publishedDaysAgo: 1, percentual: 40.0);
        $this->poll(sampleSize: 1000, publishedDaysAgo: 2, percentual: 41.0);
        $this->poll(sampleSize: 1000, publishedDaysAgo: 3, percentual: 39.0);
        // Muito destoante da mediana (~40) — descartada.
        $this->poll(sampleSize: 1000, publishedDaysAgo: 4, percentual: 90.0);

        $medias = $this->calcular();

        $this->assertCount(1, $medias);
        $this->assertSame(3, $medias[0]['pesquisas_incluidas']);
        $this->assertEqualsWithDelta(40.0, $medias[0]['media'], 0.5);
    }

    public function test_it_returns_empty_when_there_is_nothing_recent(): void
    {
        $this->poll(sampleSize: 1000, publishedDaysAgo: 400, percentual: 40.0);

        $this->assertSame([], $this->calcular());
    }

    /** @return list<array{candidato_politico_id: int|null, nome: string, partido: string|null, media: float, pesquisas_incluidas: int, amostra_total: int}> */
    private function calcular(): array
    {
        $pesquisas = PesquisaEleitoral::query()->with('resultados')->get();

        return (new MediaCalculator)->calcular($pesquisas);
    }

    private function poll(int $sampleSize, int $publishedDaysAgo, float $percentual): PesquisaEleitoral
    {
        $election = Eleicao::query()->where('ano', 2026)->firstOrFail();
        $poll = PesquisaEleitoral::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => (string) Str::uuid(),
            'external_election_id' => (string) Str::uuid(),
            'ano' => 2026,
            'uf' => 'CE',
            'cargo' => 'governador',
            'instituto' => 'Instituto Teste',
            'publicada_em' => today()->subDays($publishedDaysAgo),
            'tamanho_amostra' => $sampleSize,
            'fonte_url' => 'https://exemplo.test',
        ]);

        ResultadoPesquisaEleitoral::query()->create([
            'pesquisa_eleitoral_id' => $poll->id,
            'external_candidate_id' => 'cand-1',
            'candidato_nome' => 'Fulano',
            'partido_sigla' => 'ABC',
            'percentual' => $percentual,
        ]);

        return $poll;
    }
}
