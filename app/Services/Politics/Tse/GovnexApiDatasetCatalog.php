<?php

namespace App\Services\Politics\Tse;

use RuntimeException;

/**
 * Convenção de nome dos datasets do TSE publicados na GOVNEX API.
 *
 * O slug é derivado do nome do arquivo oficial do TSE, em kebab-case, com o
 * recorte no fim: `{nome-oficial}`, `{nome-oficial}-{ano}` ou
 * `{nome-oficial}-{ano}-{uf}`. Quem baixa o ZIP do TSE sabe o slug sem
 * consultar tabela de tradução, e a descoberta do lado do GAB deixa de
 * depender de heurística (antes eram três regras diferentes: slug exato para
 * municípios, `metadata.uf` para eleitorado e busca por texto para
 * candidaturas).
 *
 * A fonte esperada é `tse`. A busca cai para as demais fontes do catálogo
 * como rede de segurança, porque a base de municípios já esteve publicada sob
 * `govnex` — ver GovnexApiClient::locate().
 */
class GovnexApiDatasetCatalog
{
    public const SOURCE = 'tse';

    /**
     * Nome oficial de cada dataset, na mesma chave que
     * TsePoliticalDataSyncService usa internamente.
     *
     * @var array<string, string>
     */
    private const OFFICIAL_NAMES = [
        'municipalities' => 'municipio-tse-ibge',
        'electorate' => 'perfil-eleitorado',
        'candidates' => 'consulta-cand',
        'turnout' => 'detalhe-votacao-munzona',
        'candidate_votes' => 'votacao-candidato-munzona',
        'polling_locations' => 'eleitorado-local-votacao',
        'section_votes' => 'votacao-secao',
        'poll_registry' => 'pesquisa-eleitoral',
    ];

    /** Datasets publicados um por UF. */
    private const UF_SCOPED = ['electorate', 'section_votes'];

    /** Datasets sem recorte por ano (a base de referência muda sozinha). */
    private const YEARLESS = ['municipalities'];

    public static function slug(string $dataset, ?int $year = null, ?string $uf = null): string
    {
        $name = self::OFFICIAL_NAMES[$dataset]
            ?? throw new RuntimeException("Dataset do TSE sem nome oficial definido: {$dataset}.");

        if (in_array($dataset, self::YEARLESS, true)) {
            return $name;
        }

        if ($year === null) {
            throw new RuntimeException("O dataset {$dataset} é publicado por ano.");
        }

        $slug = "{$name}-{$year}";

        if (! in_array($dataset, self::UF_SCOPED, true)) {
            return $slug;
        }

        if ($uf === null || $uf === '') {
            throw new RuntimeException("O dataset {$dataset} é publicado por UF.");
        }

        return $slug.'-'.mb_strtolower($uf);
    }

    public static function isUfScoped(string $dataset): bool
    {
        return in_array($dataset, self::UF_SCOPED, true);
    }

    /**
     * Padrão legível do slug, para quem cadastra o dataset na GOVNEX API
     * (ex.: votacao-secao-{ano}-{uf}).
     */
    public static function pattern(string $dataset): string
    {
        $name = self::OFFICIAL_NAMES[$dataset]
            ?? throw new RuntimeException("Dataset do TSE sem nome oficial definido: {$dataset}.");

        if (in_array($dataset, self::YEARLESS, true)) {
            return $name;
        }

        return in_array($dataset, self::UF_SCOPED, true)
            ? $name.'-{ano}-{uf}'
            : $name.'-{ano}';
    }

    /** Prefixo que agrupa todas as UFs de um dataset por UF naquele ano. */
    public static function ufPrefix(string $dataset, int $year): string
    {
        if (! in_array($dataset, self::UF_SCOPED, true)) {
            throw new RuntimeException("O dataset {$dataset} não é publicado por UF.");
        }

        return self::OFFICIAL_NAMES[$dataset]."-{$year}-";
    }

    /** UF de um slug por UF, ou null quando o slug não segue a convenção. */
    public static function ufFromSlug(string $dataset, int $year, string $slug): ?string
    {
        $prefix = self::ufPrefix($dataset, $year);

        if (! str_starts_with($slug, $prefix)) {
            return null;
        }

        $uf = mb_strtoupper(mb_substr($slug, mb_strlen($prefix)));

        return preg_match('/^[A-Z]{2}$/', $uf) === 1 ? $uf : null;
    }
}
