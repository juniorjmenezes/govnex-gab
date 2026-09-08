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
use App\Services\Politics\Tse\GovnexApiSettings;
use App\Services\Politics\Tse\TseDatasetArchiveContract;
use App\Services\Politics\Tse\TseDatasetUrlBuilder;
use App\Services\Politics\Tse\TseUploadedArchiveValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class TsePoliticalDataSyncService
{
    /**
     * Datasets oficiais aceitos pelo fluxo manual e pelo fallback automático.
     *
     * @var list<string>
     */
    public const UPLOADABLE_DATASETS = [
        'municipalities', 'electorate', 'candidates', 'turnout', 'candidate_votes',
        'polling_locations', 'section_votes', 'poll_registry',
    ];

    /**
     * Datasets de UPLOADABLE_DATASETS que já não são obtidos por upload
     * manual nem por download automático do TSE — vêm direto da GOVNEX API
     * (ver GovnexApiClient e importElectorate()). Continuam em
     * UPLOADABLE_DATASETS (histórico global, datasetHistoryKey etc.), mas
     * são excluídos das telas/validações que ainda pedem um arquivo.
     *
     * @var list<string>
     */
    public const GOVNEX_API_DATASETS = ['electorate'];

    /**
     * Bases de referência cuja publicação não é vinculada a um ano eleitoral.
     *
     * @var list<string>
     */
    public const YEARLESS_DATASETS = ['municipalities'];

    /** @return list<string> UPLOADABLE_DATASETS sem os datasets da GOVNEX_API_DATASETS. */
    public static function fileBasedDatasets(): array
    {
        return array_values(array_diff(self::UPLOADABLE_DATASETS, self::GOVNEX_API_DATASETS));
    }

    public static function datasetRequiresYear(string $dataset): bool
    {
        return ! in_array($dataset, self::YEARLESS_DATASETS, true);
    }

    public static function datasetHistoryKey(string $dataset, int $year, ?string $uf = null): string
    {
        $key = self::datasetRequiresYear($dataset) ? "{$dataset}:{$year}" : $dataset;

        // Datasets sem UF continuam com uma chave só; datasets por UF (ex.:
        // eleitorado, votação por seção) precisam de uma entrada de
        // histórico por estado, senão o upload de um estado esconde o do
        // outro no resumo mais recente.
        return $uf !== null && $uf !== '' ? "{$key}:".mb_strtoupper($uf) : $key;
    }

    public function assertDatasetPrerequisites(string $dataset, int $year, ?string $uf = null): void
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

        if ($dataset === 'section_votes') {
            $normalizedUf = mb_strtoupper((string) $uf);

            if ($normalizedUf === '' || ! in_array($normalizedUf, $this->registeredUfs(), true)) {
                throw new RuntimeException(
                    "Nenhum gabinete ativo com o módulo Política possui município e titular do TSE resolvidos na UF {$normalizedUf}.",
                );
            }
        }
    }

    public function __construct(
        private readonly OfficeHolderCandidateResolver $holderResolver,
        private readonly GeocodingService $geocoding,
        private readonly GabineteModuleManager $modules,
        private readonly TseDatasetUrlBuilder $urlBuilder,
        private readonly TseDatasetArchiveContract $archiveContract,
        private readonly TseUploadedArchiveValidator $uploadedArchiveValidator,
        private readonly GovnexApiClient $govnexApi,
    ) {}

    public function importMunicipalities(string $archivePath, ?int $officeId = null): int
    {
        $rows = [];
        $now = now();

        $this->readCsvArchive($archivePath, function (array $row) use (&$rows, $now): void {
            $tseCode = $this->value($row, 'CD_MUNICIPIO_TSE');
            $ibgeCode = $this->value($row, 'CD_MUNICIPIO_IBGE');
            $name = $this->value($row, 'NM_MUNICIPIO_TSE', 'NM_MUNICIPIO_IBGE');
            $state = mb_strtoupper($this->value($row, 'SG_UF'));

            if ($tseCode === '' || $ibgeCode === '' || $name === '' || $state === '') {
                return;
            }

            $rows[] = [
                'codigo_tse' => str_pad($tseCode, 5, '0', STR_PAD_LEFT),
                'codigo_ibge' => $ibgeCode,
                'nome' => Str::squish($name),
                'uf' => $state,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });

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
            ->when($officeId !== null, fn ($query) => $query->whereKey($officeId))
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
     * A fonte deixou de ser o ZIP oficial do TSE — os dados vêm da GOVNEX
     * API, que publica um dataset "Perfil eleitorado" por UF (ver
     * GovnexApiClient::electorateDatasets()). Só entram as UFs que a
     * GOVNEX API já tem prontas (metadata.uf marcado + import concluído) —
     * cresce sozinho conforme mais estados forem subidos lá, sem exigir o
     * Brasil inteiro de uma vez (ver histórico desta função: uma versão
     * anterior travava com uma faixa fixa de ~5.570 municípios, que fazia
     * sentido pro ZIP único do TSE mas rejeitava qualquer cobertura
     * parcial legítima). Acionado por syncElectorateFromGovnexApi(), não
     * mais pelo fluxo de upload/download de arquivo (ver
     * GOVNEX_API_DATASETS).
     */
    public function importElectorate(
        int $year,
        ?SincronizacaoTse $run = null,
    ): int {
        $datasetsByUf = $this->govnexApi->electorateDatasets($year);

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

        foreach ($datasetsByUf as $uf => $datasetSlug) {
            $this->ensureRunIsActive($run);
            $ufIndex++;

            $municipalityCodesForUf = [];
            $unexpectedUfs = [];

            $this->govnexApi->eachRecord($datasetSlug, function (array $row) use (
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

                // O dataset foi catalogado como UF única (metadata.uf) na
                // GOVNEX API, mas não confiamos cegamente nisso — se uma
                // linha trouxer outra UF, é sinal de que o CSV importado lá
                // misturou estados, e a marcação automática errou.
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
                    $datasetSlug,
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
                        $datasetSlug,
                    ));
                }
            } else {
                Log::warning('Sem base de municípios local pra validar a plausibilidade do eleitorado desta UF — checagem pulada.', [
                    'uf' => $uf,
                    'dataset' => $datasetSlug,
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

        // Proveniência real do snapshot: o catálogo da GOVNEX API, não a URL
        // oficial do TSE recebida em $sourceUrl (ver docblock do método).
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
     * Importa as candidaturas do Brasil inteiro pra eleição do ano — não
     * restringe mais às UFs/municípios com gabinete já cadastrado, então o
     * dado já está disponível assim que qualquer gabinete daquele
     * município aparecer.
     *
     * Lê só o agregado nacional do ZIP (nationalAggregateCsvEntry) —
     * candidaturas de abrangência nacional (Presidente) não têm UF
     * própria e só existem nesse agregado, então ler os CSVs por UF em
     * vez dele perderia essas candidaturas.
     */
    public function importCandidates(string $archivePath, int $year, ?SincronizacaoTse $run = null): int
    {
        $election = Eleicao::query()->where('ano', $year)->firstOrFail();
        $municipalityIdsByCode = MunicipioEleitoral::query()->pluck('id', 'codigo_tse');
        $rows = [];
        $processedIds = [];

        // Grava tudo numa única transação — os lotes de 500 linhas já
        // evitam o padrão de 1 query por linha, mas sem isso cada lote
        // ainda seria um commit (fsync) separado, o que pesa quando o
        // dataset cobre candidatos do Brasil inteiro.
        DB::transaction(function () use ($archivePath, $election, $municipalityIdsByCode, &$rows, &$processedIds, $run): void {
            $this->readCsvArchive($archivePath, function (array $row) use (
                $election,
                $municipalityIdsByCode,
                &$rows,
                &$processedIds,
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
            }, entryFilter: $this->nationalAggregateCsvEntry(...), run: $run);

            if ($rows !== []) {
                $this->upsertCandidates($rows);
            }
        });

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
    public function importTurnout(
        string $archivePath,
        int $year,
        string $sourceUrl,
        ?SincronizacaoTse $run = null,
    ): int {
        $municipalitiesByCode = MunicipioEleitoral::query()
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        $aggregates = [];

        $this->readCsvArchive(
            $archivePath,
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
            entryFilter: $this->nationalAggregateCsvEntry(...),
            run: $run,
        );

        if ($aggregates === []) {
            return 0;
        }

        $election = Eleicao::query()->where('ano', $year)->first();
        $now = now();

        // Municípios que aparecem no arquivo mas ainda não existem na
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
     * importElectorate).
     */
    /**
     * Processa um estado por vez em vez do Brasil inteiro de uma tacada só
     * — o agregado nacional ("..._BRASIL.csv") chega a ~400 mil
     * candidaturas a vereador (município × turno × candidato), cada uma
     * carregando objetos de data; acumular tudo isso em memória antes de
     * gravar qualquer coisa no banco estoura o memory_limit padrão do PHP
     * (reproduzido: "Allowed memory size... exhausted" com 512M). Lendo e
     * gravando um estado por vez, o pico de memória fica limitado ao maior
     * estado (SP) em vez do país inteiro — a mesma robustez de cobertura do
     * agregado nacional (nenhum município fica de fora só por não ter
     * gabinete cadastrado), sem o custo de memória de fazer tudo de uma vez.
     */
    public function importCandidateVotes(
        string $archivePath,
        int $year,
        string $sourceUrl,
        ?SincronizacaoTse $run = null,
    ): int {
        $election = Eleicao::query()
            ->where('ano', $year)
            ->where('tipo', ElectionType::Municipal)
            ->first();

        if (! $election instanceof Eleicao) {
            throw new RuntimeException("Eleição municipal de {$year} não cadastrada.");
        }

        $municipalitiesByCode = MunicipioEleitoral::query()
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        $stateEntries = $this->csvEntrySizes($archivePath, $this->stateCsvEntry(...));

        if ($stateEntries === []) {
            return 0;
        }

        $totalBytes = array_sum(array_column($stateEntries, 'size'));
        $processedBytes = 0;
        $now = now();
        $totalProcessed = 0;

        foreach ($stateEntries as $entry) {
            $aggregates = [];

            $this->readCsvArchive(
                $archivePath,
                function (array $row) use (
                    $year,
                    &$aggregates,
                ): void {
                    $state = mb_strtoupper($this->value($row, 'SG_UF'));
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
                        || $office !== 'VEREADOR'
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
                        'state' => $state,
                        'election_code' => $electionCode,
                        'round' => $round,
                        'election_date' => $this->sourceDate(
                            $this->value($row, 'DT_ELEICAO'),
                            $year,
                        ),
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
                entryFilter: fn (string $name): bool => $name === $entry['name'],
                run: $run,
                progressOffsetBytes: $processedBytes,
                progressTotalBytes: $totalBytes,
            );

            $processedBytes += $entry['size'];

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
        // Municípios que aparecem no arquivo mas ainda não existem na
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
                'cargo' => 'Vereador',
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
     * importElectorate) — processando um estado por vez, pelo mesmo motivo
     * de importCandidateVotes(): o agregado nacional cobre ~90 mil locais de
     * votação e ~470 mil seções, e acumular tudo isso em memória antes de
     * gravar qualquer coisa no banco corre o mesmo risco de estourar o
     * memory_limit padrão do PHP.
     */
    public function importPollingLocations(
        string $archivePath,
        int $year,
        string $sourceUrl,
        ?SincronizacaoTse $run = null,
    ): int {
        $election = Eleicao::query()
            ->where('ano', $year)
            ->where('tipo', ElectionType::Municipal)
            ->first();

        if (! $election instanceof Eleicao) {
            throw new RuntimeException("Eleição municipal de {$year} não cadastrada.");
        }

        $municipalitiesByCode = MunicipioEleitoral::query()
            ->get(['id', 'codigo_tse'])
            ->keyBy('codigo_tse');
        $entries = $this->csvEntrySizes(
            $archivePath,
            fn (string $name): bool => $this->archiveContract->entryMatches('polling_locations', $name),
        );

        if ($entries === []) {
            return 0;
        }

        $totalBytes = array_sum(array_column($entries, 'size'));
        $processedBytes = 0;
        $now = now();
        $totalProcessed = 0;
        $chunkSize = max(1000, (int) config('services.tse.polling_locations_chunk_size', 25000));

        foreach ($entries as $entry) {
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

            $this->readCsvArchive(
                $archivePath,
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
                entryFilter: fn (string $name): bool => $name === $entry['name'],
                run: $run,
                progressOffsetBytes: $processedBytes,
                progressTotalBytes: $totalBytes,
            );

            $processedBytes += $entry['size'];
            $totalProcessed += $flush($locations, $sections);
        }

        return $totalProcessed;
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
        // existente em vez de apagá-lo com NULL num novo upload.
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
     * coordenada no arquivo do TSE, usando o serviço de geocodificação por
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

    /** Processa o ZIP de votação por seção enviado para uma única UF. */
    public function importSectionVotes(
        int $year,
        ?int $officeId = null,
        ?SincronizacaoTse $run = null,
        ?string $uploadedArchivePath = null,
        ?string $uploadedUf = null,
    ): int {
        if ($uploadedArchivePath === null || $uploadedUf === null || $uploadedUf === '') {
            throw new RuntimeException('Informe o arquivo ZIP e a UF para processar votação por seção.');
        }

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

        $uf = mb_strtoupper($uploadedUf);

        if (! isset($ufs[$uf])) {
            return 0;
        }

        $sourceUrl = $this->sourceUrl('section_votes', $year, $uf);
        $processed = $this->importSectionVotesArchive(
            $uploadedArchivePath,
            $year,
            $sourceUrl,
            $election,
            $sqCandidatoByMunicipalityCode,
            $run,
        );
        $run?->update([
            'registros_processados' => $processed,
            'fonte_url' => $sourceUrl,
        ]);

        return $processed;
    }

    /** @param array<string, list<string>> $sqCandidatoByMunicipalityCode */
    private function importSectionVotesArchive(
        string $archivePath,
        int $year,
        string $sourceUrl,
        Eleicao $election,
        array $sqCandidatoByMunicipalityCode,
        ?SincronizacaoTse $run = null,
    ): int {
        $votes = [];
        // O arquivo do TSE traz TODO candidato a vereador do estado inteiro
        // (tipicamente ~1,5 milhão de linhas por UF) — só os titulares dos
        // gabinetes interessam, uma fração mínima disso. sq_candidato é
        // único por eleição (não por município), então um conjunto achatado
        // já filtra corretamente sem perder o escopo por município abaixo.
        $relevantSequences = array_flip(array_merge([], ...array_values($sqCandidatoByMunicipalityCode)));

        $this->readCsvArchive(
            $archivePath,
            function (array $row) use ($year, $sqCandidatoByMunicipalityCode, &$votes): void {
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
                $candidateSequence = $this->value($row, 'SQ_CANDIDATO');
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
            },
            null,
            function (array $values, array $header) use ($relevantSequences): bool {
                static $index = null;

                if ($index === null) {
                    $index = array_search('SQ_CANDIDATO', $header, true);
                }

                if ($index === false) {
                    return true;
                }

                return isset($relevantSequences[trim($values[$index] ?? '')]);
            },
            $run,
        );

        if ($votes === []) {
            return 0;
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

        return $processed;
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
        string $archivePath,
        int $year,
        ?int $officeId = null,
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

        $this->readCsvArchive(
            $archivePath,
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
            fn (string $name): bool => $states->contains(
                fn (string $state): bool => str_ends_with(mb_strtoupper($name), "_{$state}.CSV"),
            ) || str_ends_with(mb_strtoupper($name), '_BRASIL.CSV'),
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
     * Valida um ZIP recém-enviado pelo admin antes de criar qualquer
     * `SincronizacaoTse`/despachar o job — feedback imediato na própria
     * requisição de upload, sem sujar o histórico com uma execução fadada a
     * falhar.
     */
    public function assertUploadedArchiveIsValid(
        string $path,
        string $dataset,
        int $year,
        ?string $uf = null,
    ): void {
        $this->uploadedArchiveValidator->assertValid($path, $dataset, $year, $uf);
    }

    /**
     * Processa um `SincronizacaoTse` criado a partir de upload manual.
     */
    public function syncUploadedDataset(SincronizacaoTse $run, string $archivePath, ?string $uf = null): int
    {
        return $this->processUploadedDataset($run, $archivePath, $uf);
    }

    /**
     * Fallback sob demanda: baixa o ZIP oficial e reutiliza o mesmo pipeline
     * de validação e importação empregado pelo upload manual.
     */
    public function syncFromOfficialSource(SincronizacaoTse $run, ?string $uf = null): int
    {
        $sourceUrl = $this->sourceUrl($run->dataset, $run->ano, $uf);
        $archivePath = null;

        $run->update([
            'fonte_url' => $sourceUrl,
            'situacao' => 'processando',
            'iniciada_em' => now(),
            'concluida_em' => null,
            'erro' => null,
        ]);

        try {
            $archivePath = $this->download($sourceUrl, $run->dataset, $run->ano);
            $this->assertUploadedArchiveIsValid(
                $archivePath,
                $run->dataset,
                $run->ano,
                $uf,
            );

            return $this->processUploadedDataset($run, $archivePath, $uf);
        } catch (Throwable $exception) {
            if ($archivePath !== null) {
                File::delete($archivePath);
            }

            $message = $exception instanceof RequestException
                && $exception->response->status() === 403
                    ? 'O TSE bloqueou o download automático (HTTP 403). Baixe o ZIP pelo link oficial e use o upload manual.'
                    : $exception->getMessage();

            $run->update([
                'situacao' => 'falhou',
                'erro' => Str::limit($message, 10000),
                'concluida_em' => now(),
            ]);

            if ($message !== $exception->getMessage()) {
                throw new RuntimeException($message, previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * Sincroniza o eleitorado a partir da GOVNEX API — o único dataset em
     * GOVNEX_API_DATASETS. Diferente de processUploadedDataset(), não lê
     * nem espera nenhum arquivo: gerencia o ciclo de vida do $run
     * (processando/concluída/falhou) em torno de importElectorate(), do
     * mesmo jeito que syncFromOfficialSource() faz para os datasets
     * baseados em arquivo.
     */
    public function syncElectorateFromGovnexApi(SincronizacaoTse $run): int
    {
        $sourceUrl = app(GovnexApiSettings::class)->url().'/sources/tse/datasets';
        $run->update([
            'fonte_url' => $sourceUrl,
            'situacao' => 'processando',
            'iniciada_em' => now(),
            'concluida_em' => null,
            'erro' => null,
        ]);

        try {
            $processed = $this->importElectorate($run->ano, $run);
            $this->ensureRunIsActive($run);
            $this->assertSomethingProcessed($processed, 'electorate', $run->ano, null);

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
     * Reprocessa, escopado a um único gabinete, cada dataset do TSE cujo
     * último ZIP de votação por seção bem-sucedido ainda está retido em
     * disco, pra UF do gabinete — usado pra "ativar" automaticamente o
     * mapa eleitoral de um gabinete recém-criado ou recém-relinkado, sem
     * exigir um novo upload manual.
     *
     * Só `section_votes` precisa disso: os demais datasets (eleitorado,
     * candidatos, comparecimento, votação nominal, locais de votação) já
     * são importados pro Brasil inteiro, independente de gabinete
     * cadastrado — o dado já está no banco assim que qualquer
     * sincronização rodar, não precisa reprocessar por gabinete. O vínculo
     * de município e o titular também já são resolvidos de forma síncrona
     * no cadastro/edição do gabinete (ver OfficeController).
     *
     * @return array<string, int> registros processados por dataset
     */
    public function syncOfficeFromRetainedArchives(Gabinete $office): array
    {
        if (
            ! $office->isActive()
            || ! $this->modules->isActive($office, GabineteModule::Politics)
            || $office->candidato_titular_id === null
        ) {
            return [];
        }

        $run = SincronizacaoTse::query()
            ->where('dataset', 'section_votes')
            ->where('uf', mb_strtoupper($office->estado))
            ->whereNotNull('arquivo_retido_path')
            ->latest('ano')
            ->first();

        if (! $run instanceof SincronizacaoTse
            || ! is_string($run->arquivo_retido_path)
            || ! File::exists($run->arquivo_retido_path)) {
            return [];
        }

        try {
            $processed = $this->importSectionVotes(
                $run->ano,
                $office->id,
                uploadedArchivePath: $run->arquivo_retido_path,
                uploadedUf: $run->uf,
            );
        } catch (Throwable $exception) {
            Log::warning('Falha ao ressincronizar votação por seção retida para um gabinete.', [
                'gabinete_id' => $office->id,
                'ano' => $run->ano,
                'uf' => $run->uf,
                'exception' => $exception->getMessage(),
            ]);

            return ['section_votes' => 0];
        }

        return ['section_votes' => $processed];
    }

    private function processUploadedDataset(
        SincronizacaoTse $run,
        string $uploadedArchivePath,
        ?string $uploadedUf = null,
    ): int {
        $year = $run->ano;
        $dataset = $run->dataset;
        $officeId = $run->gabinete_id;

        // electorate saiu daqui — não é mais obtido por upload nem por
        // download automático do TSE (ver GOVNEX_API_DATASETS e
        // syncElectorateFromGovnexApi()).
        if (! in_array($dataset, self::fileBasedDatasets(), true)) {
            throw new RuntimeException("Dataset do TSE não suportado: {$dataset}.");
        }

        $sourceUrl = $this->sourceUrl($dataset, $year, $uploadedUf);
        $run->update([
            'fonte_url' => $sourceUrl,
            'situacao' => 'processando',
            'iniciada_em' => now(),
            'concluida_em' => null,
            'erro' => null,
        ]);
        $archivePath = $uploadedArchivePath;

        try {
            $checksum = hash_file('sha256', $archivePath);

            if (! is_string($checksum)) {
                throw new RuntimeException('Não foi possível calcular o checksum do arquivo enviado.');
            }

            $run->update(['checksum_sha256' => $checksum]);

            if ($dataset === 'section_votes') {
                $processed = $this->importSectionVotes($year, $officeId, $run, $uploadedArchivePath, $uploadedUf);
                $this->ensureRunIsActive($run);
                $this->assertSomethingProcessed($processed, $dataset, $year, $officeId);
                $run->update([
                    'situacao' => 'concluida',
                    'registros_processados' => $processed,
                    'progresso_etapa' => null,
                    'progresso_percentual' => null,
                    'concluida_em' => now(),
                ]);

                return $processed;
            }

            $processed = match ($dataset) {
                'municipalities' => $this->importMunicipalities($archivePath, $officeId),
                'candidates' => $this->importCandidates($archivePath, $year, $run),
                'turnout' => $this->importTurnout($archivePath, $year, $sourceUrl, $run),
                'candidate_votes' => $this->importCandidateVotes($archivePath, $year, $sourceUrl, $run),
                'polling_locations' => $this->importPollingLocations($archivePath, $year, $sourceUrl, $run),
                'poll_registry' => $this->importElectionSurveyRegistry($archivePath, $year, $officeId),
            };

            // poll_registry só enriquece pesquisas já sincronizadas por outro
            // provider (ex.: ElectioLab) com o número de registro oficial do
            // TSE; não ter nenhuma pesquisa local pendente de vínculo é o
            // estado normal de sucesso, não uma falha de sincronização.
            if ($dataset !== 'poll_registry') {
                $this->assertSomethingProcessed($processed, $dataset, $year, $officeId);
            }

            $this->ensureRunIsActive($run);

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
        } finally {
            // Só `section_votes` é reprocessado a partir do arquivo retido
            // (ver syncOfficeFromRetainedArchives) — os demais datasets já
            // cobrem o Brasil inteiro assim que importados, então guardar o
            // ZIP deles não serve a nada, só ocupa disco.
            if ($run->situacao === 'concluida' && $dataset === 'section_votes') {
                $this->retainProcessedArchive($run, $archivePath);
            } elseif (File::exists($archivePath)) {
                File::delete($archivePath);
            }
        }
    }

    /**
     * Guarda o ZIP de uma sincronização bem-sucedida de votação por seção
     * para reprocessamento futuro (ex.: um gabinete novo cadastrado depois)
     * sem exigir novo upload. Só o mais recente por ano/UF é mantido — o
     * retido anterior dessa mesma combinação é descartado.
     */
    private function retainProcessedArchive(SincronizacaoTse $run, string $archivePath): void
    {
        $directory = storage_path('app/private/tse-retido');
        File::ensureDirectoryExists($directory);

        $previous = SincronizacaoTse::query()
            ->where('dataset', $run->dataset)
            ->where('ano', $run->ano)
            ->where('uf', $run->uf)
            ->whereKeyNot($run->id)
            ->whereNotNull('arquivo_retido_path')
            ->get();

        foreach ($previous as $old) {
            if ($old->arquivo_retido_path !== null) {
                File::delete($old->arquivo_retido_path);
            }
            $old->forceFill(['arquivo_retido_path' => null])->save();
        }

        $suffix = $run->uf !== null ? "{$run->dataset}-{$run->ano}-{$run->uf}" : "{$run->dataset}-{$run->ano}";
        $destination = $directory.DIRECTORY_SEPARATOR."{$suffix}.zip";
        File::move($archivePath, $destination);
        $run->forceFill(['arquivo_retido_path' => $destination])->save();
    }

    private function assertSomethingProcessed(int $processed, string $dataset, int $year, ?int $officeId): void
    {
        if ($processed > 0) {
            return;
        }

        Log::warning('Sincronização do TSE processou 0 registros. Verifique se o layout do arquivo do TSE mudou.', [
            'dataset' => $dataset,
            'ano' => $year,
            'gabinete_id' => $officeId,
        ]);

        throw new RuntimeException(
            "A sincronização do dataset {$dataset} do TSE para {$year} não processou registros. Verifique os pré-requisitos do gabinete e o layout publicado pelo TSE.",
        );
    }

    private function download(string $url, string $dataset, int $year): string
    {
        $directory = storage_path('app/private/tse');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR."{$dataset}-{$year}-".Str::uuid().'.zip';

        try {
            Http::withHeaders([
                'Accept' => 'application/zip, application/octet-stream;q=0.9, */*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.7',
                'Referer' => 'https://dadosabertos.tse.jus.br/',
            ])
                ->withUserAgent((string) config('services.tse.user_agent'))
                ->connectTimeout((int) config('services.tse.connect_timeout', 30))
                ->timeout((int) config('services.tse.timeout', 600))
                ->retry(3, 2000)
                ->withOptions(['sink' => $path])
                ->get($url)
                ->throw();

            if (! File::exists($path) || File::size($path) === 0) {
                throw new RuntimeException('O TSE retornou um arquivo vazio. Use o upload manual.');
            }

            $maximumBytes = max(
                1,
                (int) config('services.tse.max_download_megabytes', 2048),
            ) * 1024 * 1024;

            if (File::size($path) > $maximumBytes) {
                throw new RuntimeException('O arquivo retornado pelo TSE excede o limite configurado para download.');
            }

            return $path;
        } catch (Throwable $exception) {
            File::delete($path);

            throw $exception;
        }
    }

    /**
     * Muitos ZIPs do TSE empacotam, junto de um CSV por UF (ex.:
     * "..._CE.csv"), um agregado nacional com o Brasil inteiro de novo
     * (ex.: "..._BRASIL.csv") — cobrindo os mesmos municípios/candidatos
     * das entradas por UF. A tentativa anterior era o inverso disto (ler
     * as UFs, descartar o BRASIL), mas isso é frágil: alguns registros só
     * existem no agregado nacional (ex.: candidato a Presidente não tem
     * UF própria), então excluí-lo arriscava perder dado de verdade em vez
     * de só evitar duplicata.
     *
     * A regra mais simples e robusta é a oposta: ler só o agregado
     * nacional — por definição já é o Brasil inteiro em um único arquivo,
     * então nunca falta nada e nunca duplica nada, sem precisar entender
     * a cobertura exata de cada UF por dataset.
     */
    private function nationalAggregateCsvEntry(string $name): bool
    {
        return preg_match('/_brasil\.csv$/i', $name) === 1;
    }

    /**
     * Entrada de UF do ZIP (ex.: "..._CE.csv"), nunca o agregado nacional
     * "..._BRASIL.csv" — usado por importCandidateVotes(), que precisa
     * processar um estado por vez em vez do Brasil inteiro de uma tacada
     * só (ver csvEntrySizes()).
     */
    private function stateCsvEntry(string $name): bool
    {
        return ! $this->nationalAggregateCsvEntry($name)
            && preg_match('/_[a-z]{2}\.csv$/i', $name) === 1;
    }

    /**
     * Lista nome e tamanho descomprimido (statName, sem descompactar) de
     * cada entrada CSV do ZIP que casa com $entryFilter — usado pra
     * calcular um percentual de progresso que cubra várias chamadas de
     * readCsvArchive() (uma por UF) como se fossem uma leitura só.
     *
     * @return list<array{name: string, size: int}>
     */
    private function csvEntrySizes(string $path, callable $entryFilter): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Não foi possível abrir o arquivo ZIP do TSE.');
        }

        try {
            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (
                    ! is_string($name)
                    || ! str_ends_with(mb_strtolower($name), '.csv')
                    || ! $entryFilter($name)
                ) {
                    continue;
                }

                $stats = $zip->statName($name);
                $entries[] = ['name' => $name, 'size' => is_array($stats) ? (int) $stats['size'] : 0];
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * Os três callbacks rodam durante a própria chamada, linha a linha, e
     * nunca são guardados para depois. Sem marcá-los como imediatos, a
     * análise estática assume que um `use (&$var)` do chamador jamais é
     * executado e passa a tratar os acumuladores como sempre vazios.
     *
     * @param  callable(array<string, string>): void  $callback
     * @param  (callable(string): bool)|null  $entryFilter
     * @param  (callable(list<string>, list<string>): bool)|null  $rowFilter  Filtro barato aplicado nos valores BRUTOS de cada linha (ainda em Windows-1252, na mesma ordem do cabeçalho já convertido) antes de combiná-la com o cabeçalho em array_combine(). Alguns datasets do TSE têm milhões de linhas das quais só uma fração mínima interessa (ex.: votação por seção tem todo candidato a vereador do estado, não só o titular do gabinete) — filtrar antes evita gastar até a conversão de charset (adiada pra value(), ver utf8()) em colunas que vão ser descartadas de qualquer forma. Deve ser conservador: só retornar false quando tiver certeza de que a linha completa (após combinar) também seria descartada.
     *
     * @param-immediately-invoked-callable $callback
     * @param-immediately-invoked-callable $entryFilter
     * @param-immediately-invoked-callable $rowFilter
     */
    private function readCsvArchive(
        string $path,
        callable $callback,
        ?callable $entryFilter = null,
        ?callable $rowFilter = null,
        ?SincronizacaoTse $run = null,
        ?int $progressOffsetBytes = null,
        ?int $progressTotalBytes = null,
    ): void {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Não foi possível abrir o arquivo ZIP do TSE.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (
                    ! is_string($name)
                    || ! str_ends_with(mb_strtolower($name), '.csv')
                    || ($entryFilter !== null && ! $entryFilter($name))
                ) {
                    continue;
                }

                // Tamanho descomprimido da entrada — junto com ftell() do
                // stream (que também reflete o offset descomprimido), dá um
                // percentual real de leitura sem precisar contar linhas do
                // arquivo inteiro antes de começar.
                $stats = $zip->statName($name);
                $totalBytes = is_array($stats) ? (int) $stats['size'] : 0;
                $stream = $zip->getStream($name);

                if ($stream === false) {
                    continue;
                }

                try {
                    $header = fgetcsv($stream, null, ';', '"', '');

                    if (! is_array($header)) {
                        continue;
                    }

                    $header = array_map(
                        fn ($value): string => mb_strtoupper(trim(ltrim(
                            $this->utf8((string) $value),
                            "\xEF\xBB\xBF",
                        ))),
                        $header,
                    );
                    $rowsRead = 0;

                    while (($values = fgetcsv($stream, null, ';', '"', '')) !== false) {
                        $rowsRead++;

                        if ($run !== null && $totalBytes > 0 && $rowsRead % 5000 === 0) {
                            $this->ensureRunIsActive($run);
                            $position = ftell($stream);

                            if (is_int($position)) {
                                // Quando o chamador processa o arquivo em
                                // partes (ex.: um estado por vez, ver
                                // importCandidateVotes), o percentual
                                // precisa refletir o total do processamento
                                // inteiro, não só da parte atual — senão a
                                // barra de progresso volta pra 0% a cada
                                // nova parte.
                                $percent = $progressOffsetBytes !== null && $progressTotalBytes !== null && $progressTotalBytes > 0
                                    ? ($progressOffsetBytes + min($position, $totalBytes)) / $progressTotalBytes * 100
                                    : min($position, $totalBytes) / $totalBytes * 100;

                                $this->touchProgress($run, 'lendo_arquivo', (int) round($percent));
                            }
                        }

                        if (count($header) !== count($values)) {
                            continue;
                        }

                        if ($rowFilter !== null && ! $rowFilter($values, $header)) {
                            continue;
                        }

                        // A conversão de codificação (Windows-1252 -> UTF-8)
                        // é adiada pra dentro de value() — só as colunas que
                        // o importer efetivamente lê pagam esse custo, em
                        // vez de todas as colunas de toda linha, incluindo
                        // as descartadas por filtro logo depois no callback.
                        $callback(array_combine($header, $values));
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
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

    /** URL oficial indicada ao administrador para baixar o ZIP manualmente. */
    public function sourceUrl(string $dataset, int $year, ?string $uf = null): string
    {
        return $this->urlBuilder->official($dataset, $year, $uf);
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
