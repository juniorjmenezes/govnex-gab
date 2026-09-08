<?php

namespace App\Services\Politics\Tse;

use RuntimeException;

/**
 * Catálogo puro das URLs oficiais apresentadas no fluxo de upload manual.
 */
class TseDatasetUrlBuilder
{
    /** Datasets cuja publicação (e processamento) do TSE é segmentada por UF. */
    private const UF_REQUIRED_DATASETS = ['section_votes'];

    public function requiresUf(string $dataset): bool
    {
        return in_array($dataset, self::UF_REQUIRED_DATASETS, true);
    }

    public function official(string $dataset, int $year, ?string $uf = null): string
    {
        if ($this->requiresUf($dataset) && ($uf === null || $uf === '')) {
            throw new RuntimeException("O dataset {$dataset} do TSE é publicado por UF.");
        }

        return str_replace(
            ['{year}', '{uf}'],
            [(string) $year, mb_strtoupper((string) $uf)],
            $this->officialTemplate($dataset),
        );
    }

    /** URL com marcadores substituídos pela tela conforme ano e UF escolhidos. */
    public function officialTemplate(string $dataset): string
    {
        $baseUrl = rtrim((string) config('services.tse.cdn_url'), '/');
        $path = match ($dataset) {
            'municipalities' => 'municipio_tse_ibge/municipio_tse_ibge.zip',
            'electorate' => 'perfil_eleitorado/perfil_eleitorado_{year}.zip',
            'candidates' => 'consulta_cand/consulta_cand_{year}.zip',
            'turnout' => 'detalhe_votacao_munzona/detalhe_votacao_munzona_{year}.zip',
            'candidate_votes' => 'votacao_candidato_munzona/votacao_candidato_munzona_{year}.zip',
            'polling_locations' => 'eleitorado_locais_votacao/eleitorado_local_votacao_{year}.zip',
            'section_votes' => 'votacao_secao/votacao_secao_{year}_{uf}.zip',
            'poll_registry' => 'pesquisa_eleitoral/pesquisa_eleitoral_{year}.zip',
            default => throw new RuntimeException("Dataset do TSE não suportado: {$dataset}."),
        };

        return "{$baseUrl}/{$path}";
    }
}
