<?php

namespace App\Services\Politics;

use App\Enums\CandidateScope;
use App\Enums\ElectionType;
use App\Enums\GabineteModule;
use App\Exceptions\TseSyncCancelledException;
use App\Models\CandidatoPolitico;
use App\Models\ComparecimentoEleitoralMunicipio;
use App\Models\Eleicao;
use App\Models\EleitoradoMunicipioSnapshot;
use App\Models\Gabinete;
use App\Models\LocalVotacaoEleitoral;
use App\Models\MunicipioEleitoral;
use App\Models\PesquisaEleitoral;
use App\Models\SecaoEleitoral;
use App\Models\SincronizacaoTse;
use App\Models\VotacaoCandidatoMunicipio;
use App\Models\VotoSecaoCandidato;
use App\Services\Geocoding\GeocodingService;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\Tse\GovnexApiClient;
use App\Services\Politics\Tse\GovnexApiDatasetCatalog;
use App\Services\Politics\Tse\GovnexApiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TsePoliticalDataSyncService
{
    /**
     * Datasets do TSE que a plataforma sincroniza — todos pela GOVNEX API,
     * localizados pela convenção de nome de GovnexApiDatasetCatalog.
     *
     * @var list<string>
     */
    public const DATASETS = [
        'municipalities', 'electorate', 'candidates', 'turnout', 'candidate_votes',
        'polling_locations', 'section_votes', 'poll_registry',
    ];

    /**
     * Bases de referência cuja publicação não é vinculada a um ano eleitoral.
     *
     * @var list<string>
     */
    public const YEARLESS_DATASETS = ['municipalities'];

    /**
     * Cargos da votação nominal municipal. Vice-prefeito não entra porque não
     * recebe voto próprio — a chapa é votada no prefeito, e o vice só existe
     * na base de candidaturas (consulta-cand).
     *
     * @var list<string>
     */
    private const MUNICIPAL_OFFICES = ['PREFEITO', 'VEREADOR'];

    public static function datasetRequiresYear(string $dataset): bool
    {
        return ! in_array($dataset, self::YEARLESS_DATASETS, true);
    }

    public static function datasetHistoryKey(string $dataset, int $year, ?string $uf = null): string
    {
        $key = self::datasetRequiresYear($dataset) ? "{$dataset}:{$year}" : $dataset;

        // Datasets sem UF continuam com uma chave só; datasets por UF (ex.:
        // eleitorado, votação por seção) precisam de uma entrada de
        // histórico por estado, senão a sincronização de um estado esconde a do
        // outro no resumo mais recente.
        return $uf !== null && $uf !== '' ? "{$key}:".mb_strtoupper($uf) : $key;
    }

    public function assertDatasetPrerequisites(string $dataset, int $year): void
    {
        $requiredElectionType = match ($dataset) {
            'candidate_votes', 'polling_locations', 'section_votes' => ElectionType::Municipal,
            'poll_registry' => ElectionType::General,
            default => null,
        };

        if (in_array($dataset, ['candidates', 'turnout'], true)) {
            $electionExists = Eleicao::query()->where('ano', $year)->exists();
        } elseif ($requiredElectionType instanceof ElectionType) {
            $electionExists = Eleicao::query()
                ->where('ano', $year)
                ->where('tipo', $requiredElectionType)
                ->exists();
        } else {
            $electionExists = true;
        }

        if (! $electionExists) {
            throw new RuntimeException(
                "Não existe uma eleição compatível cadastrada para {$dataset}/{$year}.",
            );
        }

        if ($dataset === 'section_votes' && $this->registeredUfs() === []) {
            throw new RuntimeException(
                'Nenhum gabinete ativo com o módulo Política tem município e titular do TSE resolvidos — a votação por seção só é importada para os titulares.',
            );
        }
    }

    public function __construct(
        private readonly OfficeHolderCandidateResolver $holderResolver,
        private readonly GeocodingService $geocoding,
        private readonly GabineteModuleManager $modules,
        private readonly GovnexApiClient $govnexApi,
    ) {}

    /**
     * Base TSE/IBGE: um dataset único, sem recorte por ano nem por UF. É o
     * pré-requisito de todo o resto — vincula cada gabinete ao seu
     * município eleitoral e é a referência de plausibilidade do eleitorado.
     */
    public function importMunicipalities(?SincronizacaoTse $run = null): int
    {
        $located = $this->locateDataset('municipalities');
        $rows = [];

        $this->eachGovnexRecord($located, function (array $row) use (&$rows): void {
            $municipality = $this->municipalityRow($row);

            if ($municipality !== null) {
                $rows[] = $municipality;
            }
        }, $run);

        if ($rows === []) {
            throw new RuntimeException(
                "O dataset {$located['slug']} da GOVNEX API não devolveu nenhum município válido.",
            );
        }

        return $this->storeMunicipalities($rows);
    }

    /**
     * Normaliza uma linha da base de municípios (cabeçalho oficial do TSE,
     * como publicado na GOVNEX API).
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function municipalityRow(array $row): ?array
    {
        $tseCode = $this->value($row, 'CD_MUNICIPIO_TSE');
        $ibgeCode = $this->value($row, 'CD_MUNICIPIO_IBGE');
        $name = $this->value($row, 'NM_MUNICIPIO_TSE', 'NM_MUNICIPIO_IBGE');
        $state = mb_strtoupper($this->value($row, 'SG_UF'));

        if ($tseCode === '' || $ibgeCode === '' || $name === '' || $state === '') {
            return null;
        }

        $now = now();

        return [
            'codigo_tse' => str_pad($tseCode, 5, '0', STR_PAD_LEFT),
            'codigo_ibge' => $ibgeCode,
            'nome' => Str::squish($name),
            'uf' => $state,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Grava a base e vincula gabinetes ainda sem município eleitoral.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeMunicipalities(array $rows): int
    {
        MunicipioEleitoral::query()->upsert(
            $rows,
            ['codigo_tse'],
            ['codigo_ibge', 'nome', 'uf', 'updated_at'],
        );

        $municipalities = MunicipioEleitoral::query()
            ->get(['id', 'nome', 'uf'])
            ->keyBy(fn (MunicipioEleitoral $municipality): string => $this->municipalityKey(
                $municipality->uf,
                $municipality->nome,
            ));

        Gabinete::withoutGlobalScopes()
            ->whereNull('municipio_eleitoral_id')
            ->get(['id', 'municipio', 'estado'])
            ->each(function (Gabinete $office) use ($municipalities): void {
                $municipality = $municipalities->get($this->municipalityKey(
                    $office->estado,
                    $office->municipio,
                ));

                if ($municipality instanceof MunicipioEleitoral) {
                    $office->forceFill([
                        'municipio_eleitoral_id' => $municipality->id,
                    ])->save();
                }
            });

        return count($rows);
    }

    /**
     * Importa o eleitorado, independente de já existir gabinete cadastrado
     * naquele município — o vínculo de um gabinete a um município é um
     * passo à parte (resolvido na hora do cadastro/edição), não uma
     * condição para os dados já estarem disponíveis quando ele chegar.
     *
     * Os dados vêm da GOVNEX API, que publica um dataset "Perfil eleitorado" por UF (ver
     * GovnexApiClient::locateByUf()). Só entram as UFs que a GOVNEX API já
     * tem prontas (slug no padrão da convenção + import concluído) —
     * cresce sozinho conforme mais estados forem subidos lá, sem exigir o
     * Brasil inteiro de uma vez (ver histórico desta função: uma versão
     * anterior travava com uma faixa fixa de ~5.570 municípios, que fazia
     * sentido pro ZIP único do TSE mas rejeitava qualquer cobertura
     * parcial legítima). Acionado por syncFromGovnexApi().
     */
    public function importElectorate(
        int $year,
        ?SincronizacaoTse $run = null,
    ): int {
        $datasetsByUf = $this->govnexApi->locateByUf('electorate', $year);

        if ($datasetsByUf === []) {
            throw new RuntimeException(
                "Nenhum dataset de eleitorado publicado na GOVNEX API para {$year}.",
            );
        }

        // Referência pra plausibilidade por UF: quantos municípios ela JÁ
        // tinha localmente (base TSE/IBGE de importMunicipalities()) ANTES
        // desta sincronização gravar qualquer coisa — não a própria GOVNEX
        // API, pra ser um sinal genuinamente independente do que estamos
        // validando. Uma UF sem essa base local (0) fica sem checagem: não
        // dá pra distinguir "eleitorado incompleto" de "base de municípios
        // ainda não importada" sem uma referência de verdade.
        $expectedMunicipalitiesByUf = MunicipioEleitoral::query()
            ->whereIn('uf', array_keys($datasetsByUf))
            ->selectRaw('uf, count(*) as total')
            ->groupBy('uf')
            ->pluck('total', 'uf');

        $totals = [];
        $referenceDates = [];
        $ufIndex = 0;

        foreach ($datasetsByUf as $uf => $located) {
            $this->ensureRunIsActive($run);
            $ufIndex++;

            $municipalityCodesForUf = [];
            $unexpectedUfs = [];

            $this->govnexApi->eachRecord($located['source'], $located['slug'], function (array $row) use (
                $year,
                $uf,
                &$totals,
                &$referenceDates,
                &$municipalityCodesForUf,
                &$unexpectedUfs,
            ): void {
                $rowUf = mb_strtoupper($this->value($row, 'SG_UF'));
                $name = $this->value($row, 'NM_MUNICIPIO');
                $code = $this->value($row, 'CD_MUNICIPIO');
                $quantity = $this->value($row, 'QT_ELEITORES', 'QT_ELEITORES_PERFIL');

                if ($rowUf === '' || $name === '' || $code === '' || ! is_numeric($quantity)) {
                    return;
                }

                // A UF vem do sufixo do slug do dataset na GOVNEX API,
                // mas não confiamos cegamente nisso — se uma linha trouxer
                // outra UF, é sinal de que o CSV importado lá misturou
                // estados, ou de que o cadastro usou o sufixo errado.
                if ($rowUf !== $uf) {
                    $unexpectedUfs[$rowUf] = true;

                    return;
                }

                $normalizedCode = str_pad($code, 5, '0', STR_PAD_LEFT);
                $municipalityCodesForUf[$normalizedCode] = true;

                $totals[$normalizedCode] ??= [
                    'code' => $normalizedCode,
                    'name' => Str::squish($name),
                    'uf' => $rowUf,
                    'eligible' => 0,
                ];
                $totals[$normalizedCode]['eligible'] += (int) $quantity;
                $referenceDates[$normalizedCode] = $this->sourceDate(
                    $this->value($row, 'DT_GERACAO'),
                    $year,
                );
            });

            if ($unexpectedUfs !== []) {
                throw new RuntimeException(sprintf(
                    'O dataset %s está catalogado como %s na GOVNEX API, mas trouxe linhas de outra UF (%s) — confira o metadata desse dataset antes de reprocessar.',
                    $located['slug'],
                    $uf,
                    implode(', ', array_keys($unexpectedUfs)),
                ));
            }

            $actualMunicipalities = count($municipalityCodesForUf);
            $expectedMunicipalities = (int) ($expectedMunicipalitiesByUf[$uf] ?? 0);

            if ($expectedMunicipalities > 0) {
                // Municípios raramente mudam de contagem (emancipação/fusão
                // é rara) — uma tolerância pequena evita falso positivo sem
                // deixar passar uma UF claramente incompleta ou duplicada.
                $tolerance = max(2, (int) ceil($expectedMunicipalities * 0.03));

                if (
                    $actualMunicipalities < $expectedMunicipalities - $tolerance
                    || $actualMunicipalities > $expectedMunicipalities + $tolerance
                ) {
                    throw new RuntimeException(sprintf(
                        'Eleitorado de %s trouxe %d municípios — esperado ~%d pela base de municípios já importada. O dataset %s pode ter mudado de formato; confira antes de reprocessar.',
                        $uf,
                        $actualMunicipalities,
                        $expectedMunicipalities,
                        $located['slug'],
                    ));
                }
            } else {
                Log::warning('Sem base de municípios local pra validar a plausibilidade do eleitorado desta UF — checagem pulada.', [
                    'uf' => $uf,
                    'dataset' => $located['slug'],
                    'municipios_encontrados' => $actualMunicipalities,
                ]);
            }

            $this->touchProgress(
                $run,
                'lendo_govnex_api',
                (int) round($ufIndex / count($datasetsByUf) * 100),
            );
        }

        if ($totals === []) {
            return 0;
        }

        $now = now();

        $this->batchUpsert(
            MunicipioEleitoral::class,
            collect($totals)->map(fn (array $total): array => [
                'codigo_tse' => $total['code'],
                'nome' => $total['name'],
                'uf' => $total['uf'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['codigo_tse'],
            ['nome', 'uf', 'updated_at'],
        );

        $municipalityIdsByCode = MunicipioEleitoral::query()
            ->whereIn('codigo_tse', array_keys($totals))
            ->pluck('id', 'codigo_tse');

        // Proveniência do snapshot: o catálogo da GOVNEX API.
        $govnexSourceUrl = app(GovnexApiSettings::class)->url().'/sources/tse/datasets';
        $snapshotRows = [];

        foreach ($totals as $total) {
            $municipalityId = $municipalityIdsByCode->get($total['code']);

            if ($municipalityId === null) {
                continue;
            }

            $referenceDate = $referenceDates[$total['code']]
                ?? CarbonImmutable::create($year, 1, 1);

            $snapshotRows[] = [
                'municipio_eleitoral_id' => $municipalityId,
                'data_referencia' => $referenceDate->toDateString(),
                'ano_referencia' => $year,
                'eleitores_aptos' => $total['eligible'],
                'fonte_url' => $govnexSourceUrl,
                'fonte_gerada_em' => $referenceDate->startOfDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->batchUpsert(
            EleitoradoMunicipioSnapshot::class,
            $snapshotRows,
            ['municipio_eleitoral_id', 'data_referencia'],
            ['ano_referencia', 'eleitores_aptos', 'fonte_url', 'fonte_gerada_em', 'updated_at'],
            $run,
        );

        return count($totals);
    }

    /**
     * Candidaturas da eleição do ano, do Brasil inteiro — não restringe às
     * UFs/municípios com gabinete já cadastrado, então o dado já está
     * disponível assim que qualquer gabinete daquele município aparecer. O
     * dataset é o consulta_cand nacional, que inclui as candidaturas sem UF
     * própria (Presidente).
     */
    public function importCandidates(int $year, ?SincronizacaoTse $run = null): int
    {
        $election = Eleicao::query()->where('ano', $year)->firstOrFail();
        $located = $this->locateDataset('candidates', $year);
        $municipalityIdsByCode = MunicipioEleitoral::query()->pluck('id', 'codigo_tse');
        $rows = [];
        $processedIds = [];

        // Grava tudo numa única transação — os lotes de 500 linhas já
        // evitam uma query por linha, mas sem isso cada lote ainda seria um
        // commit separado, o que pesa com candidaturas do Brasil inteiro.
        DB::transaction(function () use ($located, $election, $municipalityIdsByCode, &$rows, &$processedIds, $run): void {
            $this->eachGovnexRecord(
                $located,
                function (array $row) use ($election, $municipalityIdsByCode, &$rows, &$processedIds): void {
                    $this->collectCandidateRow($row, $election, $municipalityIdsByCode, $rows, $processedIds);
                },
                $run,
            );

            if ($rows !== []) {
                $this->upsertCandidates($rows);
            }
        });

        return $this->finishCandidatesImport($election, $processedIds);
    }

    /**
     * Converte uma linha do cabecalho oficial do TSE numa candidatura e
     * acumula em lotes de 500.
     *
     * @param  array<string, string>  $row
     * @param  Collection<string, int>  $municipalityIdsByCode
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, true>  $processedIds
     */
    private function collectCandidateRow(
        array $row,
        Eleicao $election,
        Collection $municipalityIdsByCode,
        array &$rows,
        array &$processedIds,
    ): void {
        $candidateId = $this->value($row, 'SQ_CANDIDATO');
        $office = Str::squish($this->value($row, 'DS_CARGO'));
        $uf = mb_strtoupper($this->value($row, 'SG_UF'));

        if ($candidateId === '' || $office === '') {
            return;
        }

        $scope = $this->candidateScope($election->tipo, $office);

        if ($scope === null) {
            return;
        }

        $municipalityCode = $scope === CandidateScope::Municipal
            ? str_pad(
                $this->value($row, 'SG_UE', 'CD_MUNICIPIO'),
                5,
                '0',
                STR_PAD_LEFT,
            )
            : null;
        $municipalityId = $municipalityCode !== null
            ? $municipalityIdsByCode->get($municipalityCode)
            : null;

        if ($scope === CandidateScope::Municipal && $municipalityId === null) {
            return;
        }

        $sourceUpdatedAt = $this->sourceDateTime(
            $this->value($row, 'DT_GERACAO'),
            $this->value($row, 'HH_GERACAO'),
        );
        $now = now();
        $rows[] = [
            'eleicao_id' => $election->id,
            'sq_candidato' => $candidateId,
            'abrangencia' => $scope->value,
            'municipio_eleitoral_id' => $municipalityId,
            'uf' => $scope === CandidateScope::National ? null : $uf,
            'cargo' => $office,
            'nome' => Str::squish($this->value($row, 'NM_CANDIDATO')),
            'nome_urna' => Str::squish($this->value($row, 'NM_URNA_CANDIDATO')),
            'numero' => $this->nullable($this->value($row, 'NR_CANDIDATO')),
            'partido_sigla' => $this->nullable($this->value($row, 'SG_PARTIDO')),
            'partido_nome' => $this->nullable($this->value($row, 'NM_PARTIDO')),
            'situacao' => $this->nullable($this->value($row, 'DS_SITUACAO_CANDIDATURA')),
            'situacao_detalhada' => $this->nullable($this->value($row, 'DS_DETALHE_SITUACAO_CAND')),
            'fonte_atualizada_em' => $sourceUpdatedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $processedIds[$candidateId] = true;

        if (count($rows) >= 500) {
            $this->upsertCandidates($rows);
            $rows = [];
        }
    }

    /**
     * Encerramento da importação: limpa cargos fora do recorte,
     * marca a eleicao como atualizada e reresolve o titular dos gabinetes.
     *
     * @param  array<string, true>  $processedIds
     */
    private function finishCandidatesImport(Eleicao $election, array $processedIds): int
    {
        if ($election->tipo === ElectionType::General) {
            CandidatoPolitico::query()
                ->where('eleicao_id', $election->id)
                ->whereNotIn('cargo', $this->generalElectionOffices())
                ->delete();
        }

        $election->update(['fonte_atualizada_em' => now()]);

        if ($election->tipo === ElectionType::Municipal) {
            $this->holderResolver->resolve();
        }

        return count($processedIds);
    }

    /**
     * Importa o comparecimento do Brasil inteiro, sem restringir aos
     * municípios com gabinete já cadastrado (mesmo racional de
     * importElectorate).
     */
    public function importTurnout(int $year, ?SincronizacaoTse $run = null): int
    {
        $located = $this->locateDataset('turnout', $year);
        $sourceUrl = $this->datasetUrl($located);
        $municipalitiesByCode = MunicipioEleitoral::query()
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        $aggregates = [];

        $this->eachGovnexRecord(
            $located,
            function (array $row) use (
                $year,
                &$aggregates,
            ): void {
                $rowYear = $this->value($row, 'ANO_ELEICAO');
                $electionType = $this->value($row, 'CD_TIPO_ELEICAO');
                $state = mb_strtoupper($this->value($row, 'SG_UF'));
                $municipalityName = $this->value($row, 'NM_MUNICIPIO', 'NM_UE');
                $municipalityCode = str_pad(
                    $this->value($row, 'CD_MUNICIPIO', 'SG_UE'),
                    5,
                    '0',
                    STR_PAD_LEFT,
                );
                $eligible = $this->value($row, 'QT_APTOS');
                $turnout = $this->value($row, 'QT_COMPARECIMENTO');
                $abstentions = $this->value($row, 'QT_ABSTENCOES');

                if (
                    (int) $rowYear !== $year
                    || ($electionType !== '' && $electionType !== '2')
                    || $this->value($row, 'ST_VOTO_EM_TRANSITO') === 'S'
                    || ! is_numeric($eligible)
                    || ! is_numeric($turnout)
                    || ! is_numeric($abstentions)
                ) {
                    return;
                }

                $electionCode = $this->value($row, 'CD_ELEICAO');
                $round = (int) $this->value($row, 'NR_TURNO');
                $zone = $this->value($row, 'NR_ZONA');

                if ($electionCode === '' || $round < 1 || $zone === '') {
                    return;
                }

                $aggregateKey = "{$municipalityCode}:{$electionCode}:{$round}";
                $aggregates[$aggregateKey] ??= [
                    'municipality_code' => $municipalityCode,
                    'municipality_name' => Str::squish($municipalityName),
                    'state' => $state,
                    'election_code' => $electionCode,
                    'round' => $round,
                    'election_date' => $this->sourceDate(
                        $this->value($row, 'DT_ELEICAO'),
                        $year,
                    ),
                    'source_generated_at' => $this->sourceDateTime(
                        $this->value($row, 'DT_GERACAO'),
                        $this->value($row, 'HH_GERACAO'),
                    ),
                    'zones' => [],
                ];
                $currentZone = $aggregates[$aggregateKey]['zones'][$zone] ?? [
                    'eligible' => 0,
                    'turnout' => 0,
                    'abstentions' => 0,
                ];
                $aggregates[$aggregateKey]['zones'][$zone] = [
                    'eligible' => max($currentZone['eligible'], (int) $eligible),
                    'turnout' => max($currentZone['turnout'], (int) $turnout),
                    'abstentions' => max($currentZone['abstentions'], (int) $abstentions),
                ];
            },
            $run,
        );

        if ($aggregates === []) {
            return 0;
        }

        $election = Eleicao::query()->where('ano', $year)->first();
        $now = now();

        // Municípios que aparecem no dataset mas ainda não existem na
        // tabela (caso raro — importMunicipalities deveria cobrir todos)
        // entram num lote único, em vez de um updateOrCreate() por linha.
        $missingMunicipalities = collect($aggregates)
            ->reject(fn (array $aggregate): bool => $municipalitiesByCode->has($aggregate['municipality_code']))
            ->unique('municipality_code')
            ->values();

        if ($missingMunicipalities->isNotEmpty()) {
            $this->batchUpsert(
                MunicipioEleitoral::class,
                $missingMunicipalities->map(fn (array $aggregate): array => [
                    'codigo_tse' => $aggregate['municipality_code'],
                    'nome' => $aggregate['municipality_name'],
                    'uf' => $aggregate['state'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['codigo_tse'],
                ['nome', 'uf', 'updated_at'],
            );

            MunicipioEleitoral::query()
                ->whereIn('codigo_tse', $missingMunicipalities->pluck('municipality_code'))
                ->get(['id', 'codigo_tse'])
                ->each(fn (MunicipioEleitoral $municipality) => $municipalitiesByCode->put($municipality->codigo_tse, $municipality));
        }

        $turnoutRows = [];

        foreach ($aggregates as $aggregate) {
            $municipality = $municipalitiesByCode->get($aggregate['municipality_code']);

            if (! $municipality instanceof MunicipioEleitoral) {
                continue;
            }

            $turnoutRows[] = [
                'municipio_eleitoral_id' => $municipality->id,
                'codigo_eleicao_tse' => $aggregate['election_code'],
                'turno' => $aggregate['round'],
                'eleicao_id' => $election?->id,
                'ano' => $year,
                'data_eleicao' => $aggregate['election_date']->toDateString(),
                'eleitores_aptos' => collect($aggregate['zones'])->sum('eligible'),
                'comparecimento' => collect($aggregate['zones'])->sum('turnout'),
                'abstencoes' => collect($aggregate['zones'])->sum('abstentions'),
                'fonte_url' => $sourceUrl,
                'fonte_gerada_em' => $aggregate['source_generated_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->batchUpsert(
            ComparecimentoEleitoralMunicipio::class,
            $turnoutRows,
            ['municipio_eleitoral_id', 'codigo_eleicao_tse', 'turno'],
            [
                'eleicao_id', 'ano', 'data_eleicao', 'eleitores_aptos',
                'comparecimento', 'abstencoes', 'fonte_url', 'fonte_gerada_em', 'updated_at',
            ],
            $run,
        );

        return count($aggregates);
    }

    /**
     * Importa a votação nominal do Brasil inteiro, sem restringir aos
     * municípios com gabinete já cadastrado (mesmo racional de
     * importElectorate). Entram prefeito e vereador — ver MUNICIPAL_OFFICES.
     *
     * Grava um estado por vez em vez do Brasil inteiro de uma tacada só: o
     * dataset nacional passa de 700 mil linhas, cada agregado carregando
     * objetos de data, e acumular tudo antes de gravar estoura o memory_limit
     * do PHP (reproduzido: "Allowed memory size... exhausted" com 512M).
     *
     * O corte por estado vem de uma consulta filtrada por UF, não da ordem das
     * linhas: o dataset publicado traz as UFs intercaladas (medido no dataset
     * de 2024: 897 trocas de UF nas primeiras 4 mil linhas), então confiar na
     * ordem gravaria a soma de uma UF partida em duas gravações. Por isso o
     * dataset precisa declarar SG_UF como filtrável na GOVNEX API.
     */
    public function importCandidateVotes(int $year, ?SincronizacaoTse $run = null): int
    {
        $election = Eleicao::query()
            ->where('ano', $year)
            ->where('tipo', ElectionType::Municipal)
            ->first();

        if (! $election instanceof Eleicao) {
            throw new RuntimeException("Eleição municipal de {$year} não cadastrada.");
        }

        $located = $this->locateDataset('candidate_votes', $year);

        if (! in_array('SG_UF', $located['filterable'], true)) {
            throw new RuntimeException(sprintf(
                'O dataset %s precisa declarar SG_UF como campo filtrável na GOVNEX API: a votação nominal é lida um estado por vez, e as UFs vêm intercaladas no arquivo.',
                $located['slug'],
            ));
        }

        $sourceUrl = $this->datasetUrl($located);
        $municipalitiesByCode = MunicipioEleitoral::query()
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        // As UFs saem da base de municípios já importada — é a lista de
        // estados que a própria plataforma reconhece.
        $states = MunicipioEleitoral::query()
            ->distinct()
            ->orderBy('uf')
            ->pluck('uf')
            ->all();
        $now = now();
        $totalProcessed = 0;
        $stateIndex = 0;

        foreach ($states as $state) {
            $stateIndex++;
            $aggregates = [];

            $this->eachGovnexRecord(
                $located,
                function (array $row) use ($year, &$aggregates): void {
                    $rowState = mb_strtoupper($this->value($row, 'SG_UF'));
                    $municipalityName = $this->value($row, 'NM_MUNICIPIO', 'NM_UE');
                    $municipalityCode = str_pad(
                        $this->value($row, 'CD_MUNICIPIO', 'SG_UE'),
                        5,
                        '0',
                        STR_PAD_LEFT,
                    );
                    $office = Str::ascii(mb_strtoupper($this->value($row, 'DS_CARGO')));
                    $votes = $this->value($row, 'QT_VOTOS_NOMINAIS');
                    $validVotes = $this->value($row, 'QT_VOTOS_NOMINAIS_VALIDOS');

                    if (
                        (int) $this->value($row, 'ANO_ELEICAO') !== $year
                        || ($this->value($row, 'CD_TIPO_ELEICAO') !== ''
                            && $this->value($row, 'CD_TIPO_ELEICAO') !== '2')
                        || $this->value($row, 'ST_VOTO_EM_TRANSITO') === 'S'
                        || ! in_array($office, self::MUNICIPAL_OFFICES, true)
                        || ! is_numeric($votes)
                        || ! is_numeric($validVotes)
                    ) {
                        return;
                    }

                    $candidateSequence = $this->value($row, 'SQ_CANDIDATO');
                    $electionCode = $this->value($row, 'CD_ELEICAO');
                    $round = (int) $this->value($row, 'NR_TURNO');
                    $zone = $this->value($row, 'NR_ZONA');

                    if (
                        $candidateSequence === ''
                        || $electionCode === ''
                        || $round < 1
                        || $zone === ''
                    ) {
                        return;
                    }

                    $aggregateKey = "{$municipalityCode}:{$electionCode}:{$round}:{$candidateSequence}";
                    $aggregates[$aggregateKey] ??= [
                        'municipality_code' => $municipalityCode,
                        'municipality_name' => Str::squish($municipalityName),
                        'state' => $rowState,
                        'election_code' => $electionCode,
                        'round' => $round,
                        'election_date' => $this->sourceDate(
                            $this->value($row, 'DT_ELEICAO'),
                            $year,
                        ),
                        'office' => Str::squish($this->value($row, 'DS_CARGO')),
                        'candidate_sequence' => $candidateSequence,
                        'candidate_number' => $this->nullable($this->value($row, 'NR_CANDIDATO')),
                        'candidate_name' => Str::squish($this->value($row, 'NM_CANDIDATO')),
                        'candidate_ballot_name' => Str::squish($this->value($row, 'NM_URNA_CANDIDATO')),
                        'party_abbreviation' => $this->nullable($this->value($row, 'SG_PARTIDO')),
                        'party_name' => $this->nullable($this->value($row, 'NM_PARTIDO')),
                        'candidate_status' => $this->nullable($this->value(
                            $row,
                            'DS_SITUACAO_JULGAMENTO',
                            'DS_SITUACAO_CANDIDATURA',
                        )),
                        'candidate_status_detail' => $this->nullable($this->value(
                            $row,
                            'DS_DETALHE_SITUACAO_CAND',
                        )),
                        'result_status' => $this->nullable($this->value($row, 'DS_SIT_TOT_TURNO')),
                        'source_generated_at' => $this->sourceDateTime(
                            $this->value($row, 'DT_GERACAO'),
                            $this->value($row, 'HH_GERACAO'),
                        ),
                        'zones' => [],
                    ];
                    $currentZone = $aggregates[$aggregateKey]['zones'][$zone] ?? [
                        'votes' => 0,
                        'valid_votes' => 0,
                    ];
                    $aggregates[$aggregateKey]['zones'][$zone] = [
                        'votes' => max($currentZone['votes'], (int) $votes),
                        'valid_votes' => max($currentZone['valid_votes'], (int) $validVotes),
                    ];
                },
                $run,
                progressTotal: 0,
                filters: ['SG_UF' => $state],
            );

            if ($aggregates !== []) {
                $totalProcessed += $this->flushCandidateVoteAggregates(
                    $aggregates,
                    $election,
                    $municipalitiesByCode,
                    $sourceUrl,
                    $year,
                    $now,
                    $run,
                );
            }

            $this->touchProgress(
                $run,
                'lendo_govnex_api',
                (int) round($stateIndex / max(1, count($states)) * 100),
            );
        }

        $this->holderResolver->resolve();

        return $totalProcessed;
    }

    /**
     * Grava no banco os agregados de um único estado (ver
     * importCandidateVotes) — município, candidatos e votação por turno.
     * $municipalitiesByCode é enriquecida em memória à medida que novos
     * municípios aparecem, e a mesma instância é reaproveitada pelos
     * próximos estados (evita reconsultar municípios já vistos).
     *
     * @param  array<string, array{
     *     municipality_code: string,
     *     municipality_name: string,
     *     state: string,
     *     office: string,
     *     election_code: string,
     *     round: int,
     *     election_date: CarbonImmutable,
     *     candidate_sequence: string,
     *     candidate_number: string|null,
     *     candidate_name: string,
     *     candidate_ballot_name: string,
     *     party_abbreviation: string|null,
     *     party_name: string|null,
     *     candidate_status: string|null,
     *     candidate_status_detail: string|null,
     *     result_status: string|null,
     *     source_generated_at: CarbonImmutable|null,
     *     zones: array<string, array{votes: int, valid_votes: int}>,
     * }>  $aggregates
     * @param  Collection<string, MunicipioEleitoral>  $municipalitiesByCode
     */
    private function flushCandidateVoteAggregates(
        array $aggregates,
        Eleicao $election,
        Collection $municipalitiesByCode,
        string $sourceUrl,
        int $year,
        CarbonImmutable $now,
        ?SincronizacaoTse $run,
    ): int {
        // Municípios que aparecem no dataset mas ainda não existem na
        // tabela (caso raro) entram num lote único.
        $missingMunicipalities = collect($aggregates)
            ->reject(fn (array $aggregate): bool => $municipalitiesByCode->has($aggregate['municipality_code']))
            ->unique('municipality_code')
            ->values();

        if ($missingMunicipalities->isNotEmpty()) {
            $this->batchUpsert(
                MunicipioEleitoral::class,
                $missingMunicipalities->map(fn (array $aggregate): array => [
                    'codigo_tse' => $aggregate['municipality_code'],
                    'nome' => $aggregate['municipality_name'],
                    'uf' => $aggregate['state'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['codigo_tse'],
                ['nome', 'uf', 'updated_at'],
            );

            MunicipioEleitoral::query()
                ->whereIn('codigo_tse', $missingMunicipalities->pluck('municipality_code'))
                ->get(['id', 'codigo_tse'])
                ->each(fn (MunicipioEleitoral $municipality) => $municipalitiesByCode->put($municipality->codigo_tse, $municipality));
        }

        // sq_candidato é único por eleição, não por (município, turno) — um
        // mesmo candidato pode aparecer em dois turnos, então dedupa antes
        // de gravar os candidatos em si (a votação de cada turno é gravada
        // à parte, abaixo).
        //
        // Dedup via chave de array nativa (não Collection::unique()) — O(n).
        // unique() compara cada item com TODOS os já vistos (array_filter +
        // in_array por baixo), ou seja, O(n²): imperceptível nos estados
        // pequenos, mas trava por minutos no maior estado (SP, ~70 mil
        // agregados) — foi isso, e não memória, o que prendia a
        // sincronização em 24% mesmo depois de dividir por UF.
        $candidateRowsBySequence = [];

        foreach ($aggregates as $aggregate) {
            $candidateRowsBySequence[$aggregate['candidate_sequence']] ??= [
                'eleicao_id' => $election->id,
                'sq_candidato' => $aggregate['candidate_sequence'],
                'abrangencia' => CandidateScope::Municipal->value,
                'municipio_eleitoral_id' => $municipalitiesByCode->get($aggregate['municipality_code'])?->id,
                'uf' => $aggregate['state'],
                'cargo' => $aggregate['office'],
                'nome' => $aggregate['candidate_name'],
                'nome_urna' => $aggregate['candidate_ballot_name'],
                'numero' => $aggregate['candidate_number'],
                'partido_sigla' => $aggregate['party_abbreviation'],
                'partido_nome' => $aggregate['party_name'],
                'situacao' => $aggregate['candidate_status'],
                'situacao_detalhada' => $aggregate['candidate_status_detail'],
                'fonte_atualizada_em' => $aggregate['source_generated_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->batchUpsert(
            CandidatoPolitico::class,
            array_values($candidateRowsBySequence),
            ['eleicao_id', 'sq_candidato'],
            [
                'abrangencia', 'municipio_eleitoral_id', 'uf', 'cargo', 'nome', 'nome_urna',
                'numero', 'partido_sigla', 'partido_nome', 'situacao', 'situacao_detalhada',
                'fonte_atualizada_em', 'updated_at',
            ],
        );

        // Em lotes de 1000 — um estado grande (ex.: SP) tem dezenas de
        // milhares de candidatos a vereador, e um único whereIn() com todos
        // de uma vez estoura o limite de placeholders do MySQL ("Prepared
        // statement contains too many placeholders").
        // union(), não merge(): sq_candidato é um número grande, e
        // merge()/array_merge() reindexa chaves numéricas em vez de
        // preservá-las — perderíamos o mapeamento sq_candidato -> id.
        // array_keys() reaproveita a dedup já feita acima, sem repeti-la.
        $candidateIdsBySequence = collect(array_keys($candidateRowsBySequence))
            ->chunk(1000)
            ->reduce(
                fn (Collection $carry, Collection $chunk): Collection => $carry->union(
                    CandidatoPolitico::query()
                        ->where('eleicao_id', $election->id)
                        ->whereIn('sq_candidato', $chunk)
                        ->pluck('id', 'sq_candidato'),
                ),
                collect(),
            );

        $voteRows = [];

        foreach ($aggregates as $aggregate) {
            $municipality = $municipalitiesByCode->get($aggregate['municipality_code']);
            $candidateId = $candidateIdsBySequence->get($aggregate['candidate_sequence']);

            if (! $municipality instanceof MunicipioEleitoral || $candidateId === null) {
                continue;
            }

            $resultStatus = $aggregate['result_status'];
            $elected = $resultStatus !== null && str_starts_with(
                Str::ascii(mb_strtoupper($resultStatus)),
                'ELEITO',
            );

            $voteRows[] = [
                'candidato_politico_id' => $candidateId,
                'municipio_eleitoral_id' => $municipality->id,
                'codigo_eleicao_tse' => $aggregate['election_code'],
                'turno' => $aggregate['round'],
                'eleicao_id' => $election->id,
                'ano' => $year,
                'data_eleicao' => $aggregate['election_date']->toDateString(),
                'votos_nominais' => (int) collect($aggregate['zones'])->sum('votes'),
                'votos_nominais_validos' => (int) collect($aggregate['zones'])->sum('valid_votes'),
                'situacao_totalizacao' => $resultStatus,
                'eleito' => $elected,
                'fonte_url' => $sourceUrl,
                'fonte_gerada_em' => $aggregate['source_generated_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->batchUpsert(
            VotacaoCandidatoMunicipio::class,
            $voteRows,
            ['candidato_politico_id', 'municipio_eleitoral_id', 'codigo_eleicao_tse', 'turno'],
            [
                'eleicao_id', 'ano', 'data_eleicao', 'votos_nominais', 'votos_nominais_validos',
                'situacao_totalizacao', 'eleito', 'fonte_url', 'fonte_gerada_em', 'updated_at',
            ],
            $run,
        );

        return count($aggregates);
    }

    /**
     * Importa os locais de votação do Brasil inteiro, sem restringir aos
     * municípios com gabinete já cadastrado (mesmo racional de
     * importElectorate). O dataset nacional cobre ~90 mil locais e ~470 mil
     * seções: a leitura grava em lotes de seções em vez de acumular tudo em
     * memória antes de gravar qualquer coisa. Cada linha traz o local
     * completo, então um lote nunca fica com seção apontando para local de
     * um lote anterior.
     */
    public function importPollingLocations(int $year, ?SincronizacaoTse $run = null): int
    {
        $election = Eleicao::query()
            ->where('ano', $year)
            ->where('tipo', ElectionType::Municipal)
            ->first();

        if (! $election instanceof Eleicao) {
            throw new RuntimeException("Eleição municipal de {$year} não cadastrada.");
        }

        $located = $this->locateDataset('polling_locations', $year);
        $sourceUrl = $this->datasetUrl($located);
        $municipalitiesByCode = MunicipioEleitoral::query()
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        $now = now();
        $totalProcessed = 0;
        $chunkSize = max(1000, (int) config('services.tse.polling_locations_chunk_size', 25000));
        $locations = [];
        $sections = [];
        // Recebe os buffers por argumento em vez de capturá-los por
        // referência: quem acumula é quem esvazia, e o descarregamento
        // vira função pura da sua entrada.
        $flush = fn (array $pendingLocations, array $pendingSections): int => $pendingLocations === []
            ? 0
            : $this->flushPollingLocations(
                $pendingLocations,
                $pendingSections,
                $election,
                $sourceUrl,
                $now,
                $run,
            );

        $this->eachGovnexRecord(
            $located,
            function (array $row) use (
                $year,
                $municipalitiesByCode,
                &$locations,
                &$sections,
                &$totalProcessed,
                $chunkSize,
                $flush,
            ): void {
                if ((int) $this->value($row, 'AA_ELEICAO') !== $year) {
                    return;
                }

                $municipalityCode = str_pad(
                    $this->value($row, 'CD_MUNICIPIO'),
                    5,
                    '0',
                    STR_PAD_LEFT,
                );
                $municipality = $municipalitiesByCode->get($municipalityCode);

                if (! $municipality instanceof MunicipioEleitoral) {
                    return;
                }

                $zone = $this->value($row, 'NR_ZONA');
                $section = $this->value($row, 'NR_SECAO');
                $pollingLocation = $this->value($row, 'NR_LOCAL_VOTACAO');

                if ($zone === '' || $section === '' || $pollingLocation === '') {
                    return;
                }

                $locationKey = "{$municipalityCode}:{$zone}:{$pollingLocation}";
                $latitude = $this->coordinate($this->value($row, 'NR_LATITUDE'));
                $longitude = $this->coordinate($this->value($row, 'NR_LONGITUDE'));

                $locationAttributes = [
                    'municipio_eleitoral_id' => $municipality->id,
                    'nr_zona' => $zone,
                    'nr_local_votacao' => $pollingLocation,
                    'nome' => Str::squish($this->value($row, 'NM_LOCAL_VOTACAO')),
                    'tipo_local' => $this->nullable($this->value($row, 'DS_TIPO_LOCAL')),
                    'endereco' => $this->nullable(Str::squish($this->value($row, 'DS_ENDERECO'))),
                    'bairro' => $this->nullable(Str::squish($this->value($row, 'NM_BAIRRO'))),
                    'cep' => $this->nullable($this->value($row, 'NR_CEP')),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'latitude_fonte' => $latitude !== null && $longitude !== null ? 'tse' : null,
                    'fonte_gerada_em' => $this->sourceDateTime(
                        $this->value($row, 'DT_GERACAO'),
                        $this->value($row, 'HH_GERACAO'),
                    ),
                ];

                // Algumas publicações repetem o local em uma linha por
                // seção. Se a primeira vier sem coordenadas e outra as
                // trouxer, não desperdiçamos a informação oficial.
                if (
                    ! isset($locations[$locationKey])
                    || ($locations[$locationKey]['latitude'] === null && $latitude !== null && $longitude !== null)
                ) {
                    $locations[$locationKey] = $locationAttributes;
                }

                $sectionKey = "{$municipalityCode}:{$zone}:{$section}";
                $eligible = $this->value($row, 'QT_ELEITOR_SECAO');
                $currentEligible = $sections[$sectionKey]['eleitores_secao'] ?? 0;

                $sections[$sectionKey] = [
                    'municipio_eleitoral_id' => $municipality->id,
                    'nr_zona' => $zone,
                    'nr_secao' => $section,
                    'location_key' => $locationKey,
                    'eleitores_secao' => is_numeric($eligible)
                        ? max($currentEligible, (int) $eligible)
                        : $currentEligible,
                ];

                if (count($sections) >= $chunkSize) {
                    $totalProcessed += $flush($locations, $sections);
                    $locations = [];
                    $sections = [];
                }
            },
            $run,
        );

        return $totalProcessed + $flush($locations, $sections);
    }

    /**
     * Grava no banco os locais de votação e seções de um único estado (ver
     * importPollingLocations).
     *
     * @param  array<string, array{
     *     municipio_eleitoral_id: int,
     *     nr_zona: string,
     *     nr_local_votacao: string,
     *     nome: string,
     *     tipo_local: string|null,
     *     endereco: string|null,
     *     bairro: string|null,
     *     cep: string|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     latitude_fonte: string|null,
     *     fonte_gerada_em: CarbonImmutable|null,
     * }>  $locations
     * @param  array<string, array{
     *     municipio_eleitoral_id: int,
     *     nr_zona: string,
     *     nr_secao: string,
     *     location_key: string,
     *     eleitores_secao: int,
     * }>  $sections
     */
    private function flushPollingLocations(
        array $locations,
        array $sections,
        Eleicao $election,
        string $sourceUrl,
        CarbonImmutable $now,
        ?SincronizacaoTse $run,
    ): int {
        $locationRows = collect($locations)->map(fn (array $attributes): array => [
            'eleicao_id' => $election->id,
            'municipio_eleitoral_id' => $attributes['municipio_eleitoral_id'],
            'nr_zona' => $attributes['nr_zona'],
            'nr_local_votacao' => $attributes['nr_local_votacao'],
            'nome' => $attributes['nome'],
            'tipo_local' => $attributes['tipo_local'],
            'endereco' => $attributes['endereco'],
            'bairro' => $attributes['bairro'],
            'cep' => $attributes['cep'],
            'latitude' => $attributes['latitude'],
            'longitude' => $attributes['longitude'],
            'latitude_fonte' => $attributes['latitude_fonte'],
            'fonte_url' => $sourceUrl,
            'fonte_gerada_em' => $attributes['fonte_gerada_em'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->values();
        [$withOfficialCoordinates, $withoutOfficialCoordinates] = $locationRows->partition(
            fn (array $attributes): bool => $attributes['latitude'] !== null
                && $attributes['longitude'] !== null,
        );

        // Coordenadas oficiais substituem um eventual fallback geocodificado.
        // Quando o TSE não informa coordenadas, preservamos o fallback já
        // existente em vez de apagá-lo com NULL numa nova sincronização.
        $this->batchUpsert(
            LocalVotacaoEleitoral::class,
            $withOfficialCoordinates->map(fn (array $attributes): array => [
                ...$attributes,
                'geocodificado_em' => null,
            ])->all(),
            ['eleicao_id', 'municipio_eleitoral_id', 'nr_zona', 'nr_local_votacao'],
            [
                'nome', 'tipo_local', 'endereco', 'bairro', 'cep', 'latitude', 'longitude',
                'latitude_fonte', 'geocodificado_em', 'fonte_url', 'fonte_gerada_em', 'updated_at',
            ],
        );
        $this->batchUpsert(
            LocalVotacaoEleitoral::class,
            $withoutOfficialCoordinates->all(),
            ['eleicao_id', 'municipio_eleitoral_id', 'nr_zona', 'nr_local_votacao'],
            ['nome', 'tipo_local', 'endereco', 'bairro', 'cep', 'fonte_url', 'fonte_gerada_em', 'updated_at'],
        );

        // Reata os IDs recém-gravados pela mesma chave composta usada acima
        // — upsert() não devolve os IDs, então precisa de uma consulta extra
        // (uma só, não uma por local) pra poder referenciar local_votacao_
        // eleitoral_id na tabela de seções abaixo. Bem abaixo do limite de
        // placeholders do MySQL: mesmo o maior estado tem no máximo poucas
        // centenas de municípios distintos.
        // array_unique() nativo, não Collection::unique() — $locations pode
        // ter dezenas de milhares de itens no maior estado (SP), e
        // Collection::unique() é O(n²) (mesmo bug de flushCandidateVoteAggregates).
        $locationIdsByKey = LocalVotacaoEleitoral::query()
            ->where('eleicao_id', $election->id)
            ->whereIn('municipio_eleitoral_id', array_unique(array_column($locations, 'municipio_eleitoral_id')))
            ->get(['id', 'municipio_eleitoral_id', 'nr_zona', 'nr_local_votacao'])
            ->keyBy(fn (LocalVotacaoEleitoral $location): string => "{$location->municipio_eleitoral_id}:{$location->nr_zona}:{$location->nr_local_votacao}");

        $sectionRows = [];

        foreach ($sections as $attributes) {
            $locationAttributes = $locations[$attributes['location_key']] ?? null;

            if ($locationAttributes === null) {
                continue;
            }

            $locationKey = "{$locationAttributes['municipio_eleitoral_id']}:{$locationAttributes['nr_zona']}:{$locationAttributes['nr_local_votacao']}";
            $location = $locationIdsByKey->get($locationKey);

            if (! $location instanceof LocalVotacaoEleitoral) {
                continue;
            }

            $sectionRows[] = [
                'eleicao_id' => $election->id,
                'municipio_eleitoral_id' => $attributes['municipio_eleitoral_id'],
                'nr_zona' => $attributes['nr_zona'],
                'nr_secao' => $attributes['nr_secao'],
                'local_votacao_eleitoral_id' => $location->id,
                'eleitores_secao' => $attributes['eleitores_secao'] > 0
                    ? $attributes['eleitores_secao']
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->batchUpsert(
            SecaoEleitoral::class,
            $sectionRows,
            ['eleicao_id', 'municipio_eleitoral_id', 'nr_zona', 'nr_secao'],
            ['local_votacao_eleitoral_id', 'eleitores_secao', 'updated_at'],
            $run,
        );

        return count($sections);
    }

    /**
     * Preenche latitude/longitude dos locais de votação que vieram sem
     * coordenada no dataset do TSE, usando o serviço de geocodificação por
     * endereço. Executado explicitamente pelo comando de console
     * `tse:geocode-locais-votacao` depois da importação dos locais.
     */
    public function geocodePendingLocations(?SincronizacaoTse $run = null, int $limit = 100): int
    {
        $locations = LocalVotacaoEleitoral::query()
            ->whereNull('latitude')
            ->whereNotNull('endereco')
            ->with('municipioEleitoral:id,nome,uf')
            ->limit($limit)
            ->get();

        $resolved = 0;

        foreach ($locations as $location) {
            $municipality = $location->municipioEleitoral;

            $results = $this->geocoding->search(
                (string) $location->endereco,
                null,
                $location->bairro,
                $municipality->nome,
                $municipality->uf,
            );
            $best = $results[0] ?? null;

            if ($best === null) {
                continue;
            }

            $location->forceFill([
                'latitude' => $best['latitude'],
                'longitude' => $best['longitude'],
                'latitude_fonte' => 'geocodificacao',
                'geocodificado_em' => now(),
            ])->save();
            $resolved++;
            $run?->update(['registros_processados' => $resolved]);
        }

        return $resolved;
    }

    /**
     * Votação por seção só dos titulares dos gabinetes ativos com o módulo
     * Política — o dataset traz todo candidato a vereador do estado
     * (tipicamente ~1,5 milhão de linhas por UF), dos quais só os titulares
     * interessam. A GOVNEX API publica um dataset por UF
     * (votacao-secao-{ano}-{uf}); entram as UFs que têm gabinete com titular
     * resolvido e dataset já publicado. Com $officeId, restringe a um
     * gabinete (ver syncOfficeSectionVotes()).
     */
    public function importSectionVotes(
        int $year,
        ?int $officeId = null,
        ?SincronizacaoTse $run = null,
    ): int {
        $election = Eleicao::query()
            ->where('ano', $year)
            ->where('tipo', ElectionType::Municipal)
            ->first();

        if (! $election instanceof Eleicao) {
            throw new RuntimeException("Eleição municipal de {$year} não cadastrada.");
        }

        $offices = Gabinete::withoutGlobalScopes()
            ->select(['id', 'municipio_eleitoral_id', 'candidato_titular_id'])
            ->whereIn('id', $this->modules->activeOfficeIds(GabineteModule::Politics))
            ->whereNotNull('municipio_eleitoral_id')
            ->whereNotNull('candidato_titular_id')
            ->when($officeId !== null, fn ($query) => $query->whereKey($officeId))
            ->get();

        if ($offices->isEmpty()) {
            return 0;
        }

        $municipalities = MunicipioEleitoral::query()
            ->whereIn('id', $offices->pluck('municipio_eleitoral_id')->unique())
            ->get(['id', 'codigo_tse', 'uf'])
            ->keyBy('id');
        $titularsByCandidateId = CandidatoPolitico::query()
            ->whereIn('id', $offices->pluck('candidato_titular_id')->unique())
            ->get(['id', 'sq_candidato']);
        $sqCandidatoByMunicipalityCode = [];
        $ufs = [];

        foreach ($offices as $office) {
            $municipality = $municipalities->get($office->municipio_eleitoral_id);
            $titular = $titularsByCandidateId->firstWhere('id', $office->candidato_titular_id);

            if (! $municipality instanceof MunicipioEleitoral || ! $titular instanceof CandidatoPolitico) {
                continue;
            }

            $sqCandidatoByMunicipalityCode[$municipality->codigo_tse] ??= [];
            $sqCandidatoByMunicipalityCode[$municipality->codigo_tse][] = $titular->sq_candidato;
            $ufs[mb_strtoupper($municipality->uf)] = true;
        }

        if ($sqCandidatoByMunicipalityCode === []) {
            return 0;
        }

        $published = array_intersect_key($this->govnexApi->locateByUf('section_votes', $year), $ufs);

        if ($published === []) {
            if ($officeId !== null) {
                return 0;
            }

            ksort($ufs);

            throw new RuntimeException(sprintf(
                'Nenhum dataset de votação por seção publicado na GOVNEX API para %d nas UFs com gabinete (%s).',
                $year,
                implode(', ', array_keys($ufs)),
            ));
        }

        // Só os datasets lidos por inteiro entram no total do progresso: os
        // que aceitam filtro por candidato leem umas poucas linhas cada.
        $wholeReads = array_filter(
            $published,
            fn (array $located): bool => ! in_array('SQ_CANDIDATO', $located['filterable'], true),
        );
        $total = $run !== null && $wholeReads !== []
            ? array_sum(array_map(
                fn (array $located): int => $this->govnexApi->count($located['source'], $located['slug']),
                $wholeReads,
            ))
            : 0;
        $read = 0;
        $processed = 0;

        foreach ($published as $located) {
            $result = $this->importSectionVotesDataset(
                $located,
                $year,
                $election,
                $sqCandidatoByMunicipalityCode,
                $run,
                $read,
                $total,
            );
            $processed += $result['processed'];
            $read += $result['read'];
        }

        return $processed;
    }

    /**
     * @param  array{source: string, slug: string, filterable: list<string>}  $located
     * @param  array<string, list<string>>  $sqCandidatoByMunicipalityCode
     * @return array{processed: int, read: int}
     */
    private function importSectionVotesDataset(
        array $located,
        int $year,
        Eleicao $election,
        array $sqCandidatoByMunicipalityCode,
        ?SincronizacaoTse $run,
        int $progressOffset,
        int $progressTotal,
    ): array {
        $sourceUrl = $this->datasetUrl($located);
        $votes = [];
        // sq_candidato é único por eleição (não por município), então um
        // conjunto achatado já descarta de cara as linhas dos demais
        // candidatos, sem perder o escopo por município abaixo.
        $relevantSequences = array_flip(array_merge([], ...array_values($sqCandidatoByMunicipalityCode)));

        $collect = function (array $row) use ($year, $sqCandidatoByMunicipalityCode, $relevantSequences, &$votes): void {
            $candidateSequence = $this->value($row, 'SQ_CANDIDATO');

            if (! isset($relevantSequences[$candidateSequence])) {
                return;
            }

            if (
                (int) $this->value($row, 'ANO_ELEICAO') !== $year
                || (int) $this->value($row, 'NR_TURNO') !== 1
                || Str::squish($this->value($row, 'DS_CARGO')) !== 'Vereador'
            ) {
                return;
            }

            $municipalityCode = str_pad(
                $this->value($row, 'CD_MUNICIPIO'),
                5,
                '0',
                STR_PAD_LEFT,
            );
            $titulars = $sqCandidatoByMunicipalityCode[$municipalityCode] ?? [];

            if ($titulars === [] || ! in_array($candidateSequence, $titulars, true)) {
                return;
            }

            $votesQuantity = $this->value($row, 'QT_VOTOS');
            $zone = $this->value($row, 'NR_ZONA');
            $section = $this->value($row, 'NR_SECAO');
            $pollingLocation = $this->value($row, 'NR_LOCAL_VOTACAO');

            if (! is_numeric($votesQuantity) || $zone === '' || $section === '') {
                return;
            }

            $key = "{$municipalityCode}:{$zone}:{$section}:{$candidateSequence}";
            $votes[$key] = [
                'municipality_code' => $municipalityCode,
                'nr_zona' => $zone,
                'nr_secao' => $section,
                'nr_local_votacao' => $pollingLocation,
                'nm_local_votacao' => Str::squish($this->value($row, 'NM_LOCAL_VOTACAO')),
                'endereco' => $this->nullable(Str::squish(
                    $this->value($row, 'DS_LOCAL_VOTACAO_ENDERECO'),
                )),
                'candidate_sequence' => $candidateSequence,
                'votes' => (int) $votesQuantity,
                'source_generated_at' => $this->sourceDateTime(
                    $this->value($row, 'DT_GERACAO'),
                    $this->value($row, 'HH_GERACAO'),
                ),
            ];
        };

        // O dataset de uma UF traz todo candidato a vereador do estado
        // (~1,5 milhão de linhas), das quais só as dezenas do titular de cada
        // gabinete interessam. Quando a GOVNEX API declara SQ_CANDIDATO como
        // filtrável, pedimos uma consulta por titular e lemos só essas
        // linhas; sem isso, não há como escapar de ler o dataset inteiro.
        if (in_array('SQ_CANDIDATO', $located['filterable'], true)) {
            $read = 0;
            $sequences = array_keys($relevantSequences);
            $index = 0;

            foreach ($sequences as $sequence) {
                $index++;
                $read += $this->eachGovnexRecord(
                    $located,
                    $collect,
                    $run,
                    progressTotal: 0,
                    filters: ['SQ_CANDIDATO' => (string) $sequence],
                );
                $this->touchProgress(
                    $run,
                    'lendo_govnex_api',
                    (int) round($index / count($sequences) * 100),
                );
            }
        } else {
            $read = $this->eachGovnexRecord($located, $collect, $run, $progressOffset, $progressTotal);
        }

        if ($votes === []) {
            return ['processed' => 0, 'read' => $read];
        }

        $municipalitiesByCode = MunicipioEleitoral::query()
            ->whereIn('codigo_tse', collect($votes)->pluck('municipality_code')->unique())
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        $candidatesBySequence = CandidatoPolitico::query()
            ->where('eleicao_id', $election->id)
            ->whereIn('sq_candidato', collect($votes)->pluck('candidate_sequence')->unique())
            ->get(['id', 'sq_candidato'])
            ->keyBy('sq_candidato');
        $processed = 0;

        foreach ($votes as $attributes) {
            $municipality = $municipalitiesByCode->get($attributes['municipality_code']);
            $candidate = $candidatesBySequence->get($attributes['candidate_sequence']);

            if (! $municipality instanceof MunicipioEleitoral || ! $candidate instanceof CandidatoPolitico) {
                continue;
            }

            $section = SecaoEleitoral::query()->where([
                'eleicao_id' => $election->id,
                'municipio_eleitoral_id' => $municipality->id,
                'nr_zona' => $attributes['nr_zona'],
                'nr_secao' => $attributes['nr_secao'],
            ])->first();

            if (! $section instanceof SecaoEleitoral) {
                if ($attributes['nr_local_votacao'] === '') {
                    continue;
                }

                $location = LocalVotacaoEleitoral::query()->firstOrCreate(
                    [
                        'eleicao_id' => $election->id,
                        'municipio_eleitoral_id' => $municipality->id,
                        'nr_zona' => $attributes['nr_zona'],
                        'nr_local_votacao' => $attributes['nr_local_votacao'],
                    ],
                    [
                        'nome' => $attributes['nm_local_votacao'],
                        'endereco' => $attributes['endereco'],
                        'fonte_url' => $sourceUrl,
                        'fonte_gerada_em' => $attributes['source_generated_at'],
                    ],
                );
                $section = SecaoEleitoral::query()->create([
                    'eleicao_id' => $election->id,
                    'municipio_eleitoral_id' => $municipality->id,
                    'local_votacao_eleitoral_id' => $location->id,
                    'nr_zona' => $attributes['nr_zona'],
                    'nr_secao' => $attributes['nr_secao'],
                ]);
            }

            VotoSecaoCandidato::query()->updateOrCreate(
                [
                    'secao_eleitoral_id' => $section->id,
                    'candidato_politico_id' => $candidate->id,
                ],
                [
                    'votos' => $attributes['votes'],
                    'fonte_url' => $sourceUrl,
                    'fonte_gerada_em' => $attributes['source_generated_at'],
                ],
            );
            $processed++;
        }

        return ['processed' => $processed, 'read' => $read];
    }

    /**
     * Enriquece pesquisas eleitorais já sincronizadas por outro provider
     * (ex.: ElectioLab) com o número de registro oficial do TSE
     * (`registro_tse`). O portal de dados abertos do TSE só publica o
     * registro — protocolo, instituto, CNPJ, datas, metodologia, tamanho da
     * amostra — nunca o percentual por candidato; por isso este importador
     * não cria pesquisas nem resultados novos, só cruza pelo cenário
     * (eleição, UF, cargo, data de divulgação), usando o instituto como
     * critério de desempate quando há mais de uma pesquisa do mesmo dia, e
     * preenche `registro_tse` somente quando ainda está vazio — nunca
     * sobrescreve um valor já vinculado nem inventa uma pesquisa nova a
     * partir do registro.
     */
    public function importElectionSurveyRegistry(
        int $year,
        ?int $officeId = null,
        ?SincronizacaoTse $run = null,
    ): int {
        $election = Eleicao::query()
            ->where('ano', $year)
            ->where('tipo', ElectionType::General)
            ->first();

        if (! $election instanceof Eleicao) {
            return 0;
        }

        $states = Gabinete::withoutGlobalScopes()
            ->select(['estado'])
            ->when($officeId !== null, fn ($query) => $query->whereKey($officeId))
            ->get()
            ->pluck('estado')
            ->map(fn (string $state): string => mb_strtoupper($state))
            ->unique();

        if ($states->isEmpty()) {
            return 0;
        }

        $pending = PesquisaEleitoral::query()
            ->where('eleicao_id', $election->id)
            ->whereNull('registro_tse')
            ->where(fn ($query) => $query
                ->where('uf', 'BR')
                ->orWhereIn('uf', $states->all()))
            ->get(['id', 'uf', 'cargo', 'publicada_em', 'instituto']);

        if ($pending->isEmpty()) {
            return 0;
        }

        /** @var Collection<string, Collection<int, PesquisaEleitoral>> $grouped */
        $grouped = $pending->groupBy(
            fn (PesquisaEleitoral $pesquisa): string => $this->pollRegistryMatchKey(
                $pesquisa->uf,
                $pesquisa->cargo,
                $pesquisa->publicada_em->toDateString(),
            ),
        );
        $updated = 0;

        $this->eachGovnexRecord(
            $this->locateDataset('poll_registry', $year),
            function (array $row) use ($grouped, $states, &$updated): void {
                $protocol = $this->value($row, 'NR_PROTOCOLO_REGISTRO');
                $publishedAt = $this->parseRegistryDate($this->value($row, 'DT_DIVULGACAO'));

                if ($protocol === '' || $publishedAt === null) {
                    return;
                }

                $rowState = mb_strtoupper($this->value($row, 'SG_UF'));
                $institute = $this->value($row, 'NM_EMPRESA_FANTASIA', 'NM_EMPRESA');

                foreach ($this->relevantOfficesFromRegistry($row) as $cargo) {
                    $uf = $cargo === 'presidente' ? 'BR' : $rowState;

                    if ($uf !== 'BR' && ! $states->contains($uf)) {
                        continue;
                    }

                    $key = $this->pollRegistryMatchKey($uf, $cargo, $publishedAt->toDateString());
                    $candidates = $grouped->get($key) ?? collect();

                    if ($candidates->isEmpty()) {
                        continue;
                    }

                    $matchingInstitutes = $candidates->filter(
                        fn (PesquisaEleitoral $pesquisa): bool => $this->instituteNamesMatch(
                            (string) $pesquisa->instituto,
                            $institute,
                        ),
                    );
                    $match = $matchingInstitutes->count() === 1
                        ? $matchingInstitutes->first()
                        : null;

                    if (! $match instanceof PesquisaEleitoral || $match->registro_tse !== null) {
                        continue;
                    }

                    $match->forceFill(['registro_tse' => $protocol])->saveQuietly();
                    $updated++;
                }
            },
            $run,
        );

        return $updated;
    }

    /**
     * O TSE registra uma única pesquisa com múltiplos cargos numa mesma
     * linha (ex.: "Governador, Senador") quando o questionário cobre mais de
     * uma corrida. Devolve os cargos rastreados pelo GOVNEX GAB encontrados na
     * lista, em minúsculas, na mesma convenção usada por
     * {@see PesquisaEleitoral::$cargo} — cargos fora do escopo atual (ex.:
     * Deputado Federal) são ignorados.
     *
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function relevantOfficesFromRegistry(array $row): array
    {
        $raw = $this->value($row, 'DS_CARGO');

        if ($raw === '') {
            return [];
        }

        $offices = [];

        foreach (explode(',', $raw) as $office) {
            $slug = match (Str::ascii(mb_strtoupper(trim($office)))) {
                'PRESIDENTE' => 'presidente',
                'GOVERNADOR' => 'governador',
                'SENADOR' => 'senador',
                default => null,
            };

            if ($slug !== null) {
                $offices[$slug] = true;
            }
        }

        return array_keys($offices);
    }

    private function pollRegistryMatchKey(string $uf, string $cargo, string $date): string
    {
        return mb_strtoupper($uf).'|'.mb_strtolower($cargo).'|'.$date;
    }

    private function instituteNamesMatch(string $a, string $b): bool
    {
        $left = $this->normalizeInstituteName($a);
        $right = $this->normalizeInstituteName($b);

        return $left !== '' && $right !== '' && (
            $left === $right
            || (min(mb_strlen($left), mb_strlen($right)) >= 6
                && (str_contains($left, $right) || str_contains($right, $left)))
        );
    }

    private function normalizeInstituteName(string $value): string
    {
        return mb_strtoupper(Str::ascii(Str::squish($value)));
    }

    /**
     * O layout do TSE registra a data de divulgação como
     * "AAAA-MM-DD HH:MM:SS" neste dataset (diferente do "DD/MM/AAAA" usado
     * em DT_GERACAO e nos demais datasets do TSE) — tenta os dois formatos
     * para tolerar uma eventual mudança de exportação sem quebrar o
     * importador inteiro.
     */
    private function parseRegistryDate(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $value)->startOfDay();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Executa a sincronização de um dataset, registrando início, conclusão e
     * falha na própria `SincronizacaoTse` — é o que a tela de sincronização
     * política acompanha.
     */
    public function syncFromGovnexApi(SincronizacaoTse $run): int
    {
        $run->update([
            'fonte_url' => $this->datasetPatternUrl($run->dataset, $run->ano),
            'situacao' => 'processando',
            'iniciada_em' => now(),
            'concluida_em' => null,
            'erro' => null,
        ]);

        try {
            $processed = match ($run->dataset) {
                'municipalities' => $this->importMunicipalities($run),
                'electorate' => $this->importElectorate($run->ano, $run),
                'candidates' => $this->importCandidates($run->ano, $run),
                'turnout' => $this->importTurnout($run->ano, $run),
                'candidate_votes' => $this->importCandidateVotes($run->ano, $run),
                'polling_locations' => $this->importPollingLocations($run->ano, $run),
                'section_votes' => $this->importSectionVotes($run->ano, null, $run),
                'poll_registry' => $this->importElectionSurveyRegistry($run->ano, null, $run),
                default => throw new RuntimeException("Dataset do TSE não suportado: {$run->dataset}."),
            };
            $this->ensureRunIsActive($run);

            // poll_registry só enriquece pesquisas já sincronizadas por outro
            // provider com o número de registro oficial do TSE; não ter
            // nenhuma pesquisa local pendente de vínculo é o estado normal de
            // sucesso, não uma falha de sincronização.
            if ($run->dataset !== 'poll_registry') {
                $this->assertSomethingProcessed($processed, $run->dataset, $run->ano);
            }

            $run->update([
                'situacao' => 'concluida',
                'registros_processados' => $processed,
                'progresso_etapa' => null,
                'progresso_percentual' => null,
                'concluida_em' => now(),
            ]);

            return $processed;
        } catch (TseSyncCancelledException) {
            $run->refresh();

            return 0;
        } catch (Throwable $exception) {
            $run->update([
                'situacao' => 'falhou',
                'erro' => Str::limit($exception->getMessage(), 10000),
                'progresso_etapa' => null,
                'progresso_percentual' => null,
                'concluida_em' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{source: string, slug: string, filterable: list<string>}
     */
    private function locateDataset(string $dataset, ?int $year = null): array
    {
        $located = $this->govnexApi->locate($dataset, $year);

        if ($located === null) {
            throw new RuntimeException($this->govnexApi->unavailableMessage($dataset, $year));
        }

        return $located;
    }

    /** @param array{source: string, slug: string, filterable: list<string>} $located */
    private function datasetUrl(array $located): string
    {
        return app(GovnexApiSettings::class)->url()."/sources/{$located['source']}/datasets/{$located['slug']}";
    }

    /**
     * Proveniência gravada na execução antes de localizar o dataset: o slug
     * esperado no catálogo, com `*` onde a fonte e a UF variam.
     */
    private function datasetPatternUrl(string $dataset, int $year): string
    {
        $slug = GovnexApiDatasetCatalog::isUfScoped($dataset)
            ? GovnexApiDatasetCatalog::ufPrefix($dataset, $year).'*'
            : GovnexApiDatasetCatalog::slug($dataset, $year);

        return app(GovnexApiSettings::class)->url().'/sources/*/datasets/'.$slug;
    }

    /**
     * Percorre um dataset da GOVNEX API linha a linha. A cada 5.000 linhas
     * confere se a execução foi cancelada e atualiza o percentual de
     * leitura, calculado sobre o total publicado. Quando o chamador lê
     * vários datasets em sequência (votação por seção, uma UF por vez),
     * $progressOffset e $progressTotal fazem o percentual cobrir a leitura
     * inteira, em vez de voltar a 0% a cada dataset.
     *
     * @param  array{source: string, slug: string, filterable: list<string>}  $located
     * @param  callable(array<string, string>): void  $callback
     * @param  array<string, string>  $filters  ver GovnexApiClient::eachRecord()
     * @return int linhas lidas
     *
     * @param-immediately-invoked-callable $callback
     */
    private function eachGovnexRecord(
        array $located,
        callable $callback,
        ?SincronizacaoTse $run = null,
        int $progressOffset = 0,
        ?int $progressTotal = null,
        array $filters = [],
    ): int {
        $total = $progressTotal
            ?? ($run !== null ? $this->govnexApi->count($located['source'], $located['slug'], $filters) : 0);
        $read = 0;

        $this->govnexApi->eachRecord(
            $located['source'],
            $located['slug'],
            function (array $row) use (&$read, $total, $run, $progressOffset, $callback): void {
                $read++;

                if ($run !== null && $read % 5000 === 0) {
                    $this->ensureRunIsActive($run);

                    if ($total > 0) {
                        $this->touchProgress(
                            $run,
                            'lendo_govnex_api',
                            (int) round(min($progressOffset + $read, $total) / $total * 100),
                        );
                    }
                }

                $callback($row);
            },
            filters: $filters,
        );

        return $read;
    }

    /**
     * Importa a votação por seção de um gabinete recém-criado ou
     * recém-relinkado, assim que o titular dele é resolvido — sem esperar o
     * administrador sincronizar a votação por seção de novo. Só roda para o
     * último ano que já tem uma sincronização de votação por seção
     * concluída: se esse dataset nunca foi sincronizado, um gabinete novo não
     * dispara a importação sozinho.
     *
     * Os demais datasets já cobrem o Brasil inteiro, independente de gabinete
     * cadastrado; só a votação por seção é filtrada pelos titulares. O
     * vínculo de município e o titular são resolvidos de forma síncrona no
     * cadastro/edição do gabinete (ver OfficeController).
     *
     * @return array<string, int> registros processados por dataset
     */
    public function syncOfficeSectionVotes(Gabinete $office): array
    {
        if (
            ! $office->isActive()
            || ! $this->modules->isActive($office, GabineteModule::Politics)
            || $office->candidato_titular_id === null
        ) {
            return [];
        }

        $year = SincronizacaoTse::query()
            ->whereNull('gabinete_id')
            ->where('dataset', 'section_votes')
            ->where('situacao', 'concluida')
            ->max('ano');

        if ($year === null) {
            return [];
        }

        try {
            $processed = $this->importSectionVotes((int) $year, $office->id);
        } catch (Throwable $exception) {
            Log::warning('Falha ao importar a votação por seção de um gabinete pela GOVNEX API.', [
                'gabinete_id' => $office->id,
                'ano' => $year,
                'uf' => $office->estado,
                'exception' => $exception->getMessage(),
            ]);

            return ['section_votes' => 0];
        }

        return ['section_votes' => $processed];
    }

    private function assertSomethingProcessed(int $processed, string $dataset, int $year): void
    {
        if ($processed > 0) {
            return;
        }

        Log::warning('Sincronização da GOVNEX API processou 0 registros. Verifique se as colunas do dataset mudaram.', [
            'dataset' => $dataset,
            'ano' => $year,
        ]);

        throw new RuntimeException(
            "A sincronização do dataset {$dataset} para {$year} não processou registros. Verifique os pré-requisitos e as colunas do dataset publicado na GOVNEX API.",
        );
    }

    /**
     * Atualiza a etapa/percentual de progresso de um run em andamento, sem
     * disparar eventos do Eloquent — evita sobrecarregar o banco com um
     * UPDATE completo do model a cada tick de leitura/gravação.
     */
    private function touchProgress(?SincronizacaoTse $run, string $stage, int $percent): void
    {
        if ($run === null) {
            return;
        }

        $this->ensureRunIsActive($run);

        $percent = max(0, min(100, $percent));

        if ($run->progresso_etapa === $stage && $run->progresso_percentual === $percent) {
            return;
        }

        $run->progresso_etapa = $stage;
        $run->progresso_percentual = $percent;

        DB::table('sincronizacoes_tse')
            ->where('id', $run->id)
            ->update([
                'progresso_etapa' => $stage,
                'progresso_percentual' => $percent,
            ]);
    }

    /**
     * Grava um conjunto de linhas já deduplicadas em lotes, dentro de uma
     * única transação — substitui o padrão anterior de um updateOrCreate()
     * (1 SELECT + 1 INSERT/UPDATE) por linha, que ficou proibitivo depois
     * que os datasets passaram a cobrir o Brasil inteiro em vez de só os
     * municípios com gabinete cadastrado. As colunas de $uniqueBy precisam
     * corresponder a um índice único de verdade no banco (conferido contra
     * a migration de cada tabela), senão upsert() insere duplicatas em vez
     * de atualizar.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $updateColumns
     */
    private function batchUpsert(
        string $modelClass,
        array $rows,
        array $uniqueBy,
        array $updateColumns,
        ?SincronizacaoTse $run = null,
        string $progressStage = 'gravando_registros',
    ): void {
        if ($rows === []) {
            return;
        }

        $chunks = array_chunk($rows, 1000);
        $totalChunks = count($chunks);

        DB::transaction(function () use ($modelClass, $chunks, $totalChunks, $uniqueBy, $updateColumns, $run, $progressStage): void {
            foreach ($chunks as $index => $chunk) {
                $this->ensureRunIsActive($run);
                $modelClass::query()->upsert($chunk, $uniqueBy, $updateColumns);

                $this->touchProgress(
                    $run,
                    $progressStage,
                    (int) round(($index + 1) / $totalChunks * 100),
                );
            }
        });
    }

    private function ensureRunIsActive(?SincronizacaoTse $run): void
    {
        if ($run === null) {
            return;
        }

        $status = DB::table('sincronizacoes_tse')->where('id', $run->id)->value('situacao');

        if ($status === 'cancelada') {
            throw new TseSyncCancelledException;
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertCandidates(array $rows): void
    {
        CandidatoPolitico::query()->upsert(
            $rows,
            ['eleicao_id', 'sq_candidato'],
            [
                'abrangencia',
                'municipio_eleitoral_id',
                'uf',
                'cargo',
                'nome',
                'nome_urna',
                'numero',
                'partido_sigla',
                'partido_nome',
                'situacao',
                'situacao_detalhada',
                'fonte_atualizada_em',
                'updated_at',
            ],
        );
    }

    private function candidateScope(ElectionType $type, string $office): ?CandidateScope
    {
        if ($type === ElectionType::Municipal) {
            return CandidateScope::Municipal;
        }

        return match (Str::ascii(mb_strtoupper($office))) {
            'PRESIDENTE' => CandidateScope::National,
            'GOVERNADOR',
            'SENADOR',
            'DEPUTADO FEDERAL',
            'DEPUTADO ESTADUAL',
            'DEPUTADO DISTRITAL' => CandidateScope::State,
            default => null,
        };
    }

    /** @return list<string> UFs prontas para processar votação por seção. */
    public function registeredUfs(): array
    {
        return array_values(Gabinete::withoutGlobalScopes()
            ->whereIn('id', $this->modules->activeOfficeIds(GabineteModule::Politics))
            ->whereNotNull('municipio_eleitoral_id')
            ->whereNotNull('candidato_titular_id')
            ->pluck('estado')
            ->filter()
            ->map(fn (string $uf): string => mb_strtoupper($uf))
            ->unique()
            ->sort()
            ->all());
    }

    /** @return list<string> */
    private function generalElectionOffices(): array
    {
        return [
            'PRESIDENTE',
            'GOVERNADOR',
            'SENADOR',
            'DEPUTADO FEDERAL',
            'DEPUTADO ESTADUAL',
            'DEPUTADO DISTRITAL',
        ];
    }

    private function municipalityKey(string $uf, string $name): string
    {
        return mb_strtoupper(Str::ascii(Str::squish($uf.'|'.$name)));
    }

    /**
     * Acha o município eleitoral (base TSE/IBGE) que corresponde a um par
     * estado+município cadastrado no gabinete — usado para relinkar
     * `municipio_eleitoral_id` quando o admin edita o município de um
     * gabinete já vinculado (a edição por si só não atualiza o vínculo,
     * essa correspondência só acontece na importação).
     */
    public function resolveMunicipality(string $estado, string $municipio): ?MunicipioEleitoral
    {
        $key = $this->municipalityKey($estado, $municipio);

        return MunicipioEleitoral::query()
            ->where('uf', mb_strtoupper($estado))
            ->get()
            ->first(fn (MunicipioEleitoral $candidate): bool => $this->municipalityKey($candidate->uf, $candidate->nome) === $key);
    }

    /** @param array<string, string> $row */
    private function value(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return trim($this->utf8((string) $row[$key]));
            }
        }

        return '';
    }

    /**
     * iconv() converte Windows-1252 -> UTF-8 bem mais rápido que
     * mb_convert_encoding() — relevante aqui porque essa função roda
     * milhões de vezes nos arquivos maiores do TSE. `//IGNORE` descarta
     * bytes que não mapeiam pra UTF-8 em vez de falhar.
     */
    private function utf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    private function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }

    private function coordinate(string $value): ?float
    {
        if ($value === '' || $value === '-1' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function sourceDate(string $value, int $fallbackYear): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('d/m/Y', $value)->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::create($fallbackYear, 1, 1);
        }
    }

    private function sourceDateTime(string $date, string $time): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat(
                'd/m/Y H:i:s',
                trim("{$date} {$time}"),
                'America/Sao_Paulo',
            )->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
