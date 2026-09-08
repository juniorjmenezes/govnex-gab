<?php

namespace App\Services\Politics\Tse;

use RuntimeException;

/**
 * Contrato único entre a validação do upload e a leitura dos datasets.
 *
 * Manter nomes de entradas e colunas aqui evita aceitar na requisição um ZIP
 * que o importador inevitavelmente ignorará algumas horas depois.
 */
class TseDatasetArchiveContract
{
    /**
     * @var array<string, array{
     *     columns: list<list<string>>,
     *     entry: string,
     *     year_column?: string,
     *     protocol_year?: bool,
     *     uf_column?: string
     * }>
     */
    private const CONTRACTS = [
        'municipalities' => [
            'entry' => 'municipalities',
            'columns' => [
                ['SG_UF'],
                ['CD_MUNICIPIO_TSE'],
                ['NM_MUNICIPIO_TSE', 'NM_MUNICIPIO_IBGE'],
                ['CD_MUNICIPIO_IBGE'],
            ],
        ],
        'electorate' => [
            'entry' => 'national',
            'columns' => [
                ['SG_UF'],
                ['CD_MUNICIPIO'],
                ['NM_MUNICIPIO'],
                ['QT_ELEITORES', 'QT_ELEITORES_PERFIL'],
            ],
        ],
        'candidates' => [
            'entry' => 'national',
            'columns' => [
                ['ANO_ELEICAO'],
                ['SG_UF'],
                ['SQ_CANDIDATO'],
                ['DS_CARGO'],
                ['NM_CANDIDATO'],
                ['NM_URNA_CANDIDATO'],
                ['NR_CANDIDATO'],
            ],
            'year_column' => 'ANO_ELEICAO',
        ],
        'turnout' => [
            'entry' => 'national',
            'columns' => [
                ['ANO_ELEICAO'],
                ['SG_UF'],
                ['CD_MUNICIPIO', 'SG_UE'],
                ['CD_ELEICAO'],
                ['NR_TURNO'],
                ['NR_ZONA'],
                ['QT_APTOS'],
                ['QT_COMPARECIMENTO'],
                ['QT_ABSTENCOES'],
            ],
            'year_column' => 'ANO_ELEICAO',
        ],
        'candidate_votes' => [
            'entry' => 'state',
            'columns' => [
                ['ANO_ELEICAO'],
                ['SG_UF'],
                ['CD_MUNICIPIO', 'SG_UE'],
                ['CD_ELEICAO'],
                ['NR_TURNO'],
                ['NR_ZONA'],
                ['DS_CARGO'],
                ['SQ_CANDIDATO'],
                ['QT_VOTOS_NOMINAIS'],
                ['QT_VOTOS_NOMINAIS_VALIDOS'],
            ],
            'year_column' => 'ANO_ELEICAO',
        ],
        'polling_locations' => [
            // O ZIP de 2024 possui uma única entrada nacional chamada
            // eleitorado_local_votacao_2024.csv (sem sufixo _BRASIL).
            // Também toleramos publicações futuras segmentadas por UF.
            'entry' => 'polling_locations',
            'columns' => [
                ['AA_ELEICAO'],
                ['SG_UF'],
                ['CD_MUNICIPIO'],
                ['NR_ZONA'],
                ['NR_SECAO'],
                ['NR_LOCAL_VOTACAO'],
                ['NM_LOCAL_VOTACAO'],
            ],
            'year_column' => 'AA_ELEICAO',
        ],
        'section_votes' => [
            'entry' => 'selected_state',
            'columns' => [
                ['ANO_ELEICAO'],
                ['SG_UF'],
                ['CD_MUNICIPIO'],
                ['NR_TURNO'],
                ['NR_ZONA'],
                ['NR_SECAO'],
                ['DS_CARGO'],
                ['SQ_CANDIDATO'],
                ['QT_VOTOS'],
            ],
            'year_column' => 'ANO_ELEICAO',
            'uf_column' => 'SG_UF',
        ],
        'poll_registry' => [
            'entry' => 'state_or_national',
            'columns' => [
                ['SG_UF'],
                ['DS_CARGO'],
                ['DT_DIVULGACAO'],
                ['NR_PROTOCOLO_REGISTRO'],
                ['NM_EMPRESA_FANTASIA', 'NM_EMPRESA'],
            ],
            'protocol_year' => true,
        ],
    ];

    /** @return array{columns: list<list<string>>, entry: string, year_column?: string, protocol_year?: bool, uf_column?: string} */
    public function for(string $dataset): array
    {
        return self::CONTRACTS[$dataset]
            ?? throw new RuntimeException("Dataset do TSE não suportado para upload: {$dataset}.");
    }

    public function entryMatches(string $dataset, string $name, ?string $uf = null): bool
    {
        if (! str_ends_with(mb_strtolower($name), '.csv')) {
            return false;
        }

        $mode = $this->for($dataset)['entry'];

        return match ($mode) {
            'municipalities' => preg_match('/municipio_tse_ibge\.csv$/i', $name) === 1,
            'national' => preg_match('/_brasil\.csv$/i', $name) === 1,
            'state' => $this->isStateEntry($name),
            'state_or_national' => $this->isStateEntry($name)
                || preg_match('/_brasil\.csv$/i', $name) === 1,
            'polling_locations' => $this->isStateEntry($name)
                || preg_match('/eleitorado_local_votacao_\d{4}\.csv$/i', $name) === 1,
            'selected_state' => $uf !== null
                && preg_match('/_'.preg_quote(mb_strtoupper($uf), '/').'\.csv$/i', $name) === 1,
            default => false,
        };
    }

    public function isStateEntry(string $name): bool
    {
        return preg_match('/_[a-z]{2}\.csv$/i', $name) === 1
            && preg_match('/_brasil\.csv$/i', $name) !== 1;
    }
}
