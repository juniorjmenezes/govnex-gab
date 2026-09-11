<?php

namespace Tests\Feature;

use App\Services\Politics\Tse\GovnexApiDatasetCatalog;
use App\Services\Politics\TsePoliticalDataSyncService;
use RuntimeException;
use Tests\TestCase;

/**
 * A convenção de slug é o contrato entre os dois sistemas: é por ela que o
 * GAB encontra na GOVNEX API o dataset que alguém cadastrou do outro lado.
 * Mudar qualquer string aqui quebra sincronizações já publicadas.
 */
class GovnexApiDatasetCatalogTest extends TestCase
{
    public function test_it_builds_the_agreed_slug_for_each_dataset(): void
    {
        $this->assertSame(
            'municipio-tse-ibge',
            GovnexApiDatasetCatalog::slug('municipalities'),
        );
        $this->assertSame(
            'consulta-cand-2024',
            GovnexApiDatasetCatalog::slug('candidates', 2024),
        );
        $this->assertSame(
            'detalhe-votacao-munzona-2024',
            GovnexApiDatasetCatalog::slug('turnout', 2024),
        );
        $this->assertSame(
            'perfil-eleitorado-2026-ce',
            GovnexApiDatasetCatalog::slug('electorate', 2026, 'CE'),
        );
        $this->assertSame(
            'votacao-secao-2024-sp',
            GovnexApiDatasetCatalog::slug('section_votes', 2024, 'sp'),
        );
    }

    public function test_it_describes_the_slug_pattern_for_whoever_registers_the_dataset(): void
    {
        $this->assertSame('municipio-tse-ibge', GovnexApiDatasetCatalog::pattern('municipalities'));
        $this->assertSame('consulta-cand-{ano}', GovnexApiDatasetCatalog::pattern('candidates'));
        $this->assertSame('perfil-eleitorado-{ano}-{uf}', GovnexApiDatasetCatalog::pattern('electorate'));
    }

    public function test_a_yearless_dataset_ignores_the_year(): void
    {
        $this->assertSame(
            'municipio-tse-ibge',
            GovnexApiDatasetCatalog::slug('municipalities', 2026),
        );
    }

    public function test_it_refuses_to_guess_a_missing_recorte(): void
    {
        $this->expectException(RuntimeException::class);
        GovnexApiDatasetCatalog::slug('electorate', 2026);
    }

    public function test_it_reads_the_uf_back_from_a_slug(): void
    {
        $this->assertSame(
            'CE',
            GovnexApiDatasetCatalog::ufFromSlug('electorate', 2026, 'perfil-eleitorado-2026-ce'),
        );
        // Ano diferente do prefixo não é do recorte pedido.
        $this->assertNull(
            GovnexApiDatasetCatalog::ufFromSlug('electorate', 2026, 'perfil-eleitorado-2024-ce'),
        );
        // Sufixo que não é sigla de UF não vira UF.
        $this->assertNull(
            GovnexApiDatasetCatalog::ufFromSlug('electorate', 2026, 'perfil-eleitorado-2026-brasil'),
        );
    }

    public function test_every_dataset_the_app_can_sync_has_an_official_name(): void
    {
        foreach (TsePoliticalDataSyncService::DATASETS as $dataset) {
            $slug = GovnexApiDatasetCatalog::slug($dataset, 2024, 'CE');

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $slug,
                "O slug de {$dataset} precisa ser kebab-case — é o formato que a GOVNEX API aceita.",
            );
            $this->assertLessThanOrEqual(
                100,
                strlen($slug),
                "O slug de {$dataset} passa do limite de 100 caracteres da GOVNEX API.",
            );
        }
    }
}
