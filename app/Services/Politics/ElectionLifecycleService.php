<?php

namespace App\Services\Politics;

use App\Exceptions\ElectionLifecycleException;
use App\Jobs\SyncDatasetFromGovnexApi;
use App\Jobs\TallyElection;
use App\Models\Candidate;
use App\Models\CandidateElectionResult;
use App\Models\CandidateMunicipalityVote;
use App\Models\Election;
use App\Models\TseSync;
use App\Models\User;
use App\Services\Politics\Tse\GovnexApiDatasetCatalog;
use App\Services\Politics\Tse\GovnexApiSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Ciclo de vida de uma eleição, num lugar só: cadastrada a eleição, os
 * datasets públicos do TSE que valem para o tipo dela são sincronizados
 * (GOVNEX API) e, depois da votação, ela é apurada (apuração ao vivo do TSE).
 *
 * Este serviço só orquestra — a importação de cada dataset continua no
 * TsePoliticalDataSyncService, a gravação do resultado de cada disputa no
 * ElectionResultRecorder e a classificação para o 2º turno no
 * SecondRoundClassifier. A tela da eleição, o comando agendado, o job e a
 * tela de apuração ao vivo entram todos por aqui.
 *
 * Tudo é dado público da eleição: nada depende de gabinete. A sincronização
 * de pesquisas do PollingData, que é por gabinete, fica de fora
 * (OfficePoliticalDataSyncDispatcher).
 */
class ElectionLifecycleService
{
    /**
     * Cargos com resultado próprio, na ordem da tela, com a fonte do
     * resultado: a apuração do TSE gravada (`tse`) ou a votação nominal
     * sincronizada (`candidate_votes`). Vices e suplentes não recebem voto
     * próprio e ficam de fora.
     *
     * @var array<string, array{label: string, source: string}>
     */
    private const RESULT_OFFICES = [
        'PRESIDENTE' => ['label' => 'Presidente', 'source' => 'tse'],
        'GOVERNADOR' => ['label' => 'Governador', 'source' => 'tse'],
        'SENADOR' => ['label' => 'Senador', 'source' => 'tse'],
        'DEPUTADO FEDERAL' => ['label' => 'Deputado federal', 'source' => 'tse'],
        'DEPUTADO ESTADUAL' => ['label' => 'Deputado estadual', 'source' => 'tse'],
        'DEPUTADO DISTRITAL' => ['label' => 'Deputado distrital', 'source' => 'tse'],
        'PREFEITO' => ['label' => 'Prefeito', 'source' => 'candidate_votes'],
        'VEREADOR' => ['label' => 'Vereador', 'source' => 'candidate_votes'],
    ];

    public function __construct(
        private readonly TsePoliticalDataSyncService $datasets,
        private readonly ElectionResultRecorder $results,
        private readonly SecondRoundClassifier $secondRound,
    ) {}

    // ---- Datasets --------------------------------------------------------

    /**
     * Datasets do TSE que valem para a eleição (pelo tipo dela), cada um com
     * a última execução global daquele ano — a base de municípios, que não
     * tem ano, com a última de qualquer ano.
     *
     * @return list<array{key: string, requires_year: bool, slug: string, latest_sync: array<string, mixed>|null}>
     */
    public function datasets(Election $election): array
    {
        $keys = TsePoliticalDataSyncService::datasetsFor($election->tipo);
        $latest = TseSync::query()
            ->whereNull('gabinete_id')
            ->whereIn('dataset', $keys)
            ->where(fn ($query) => $query
                ->where('ano', $election->ano)
                ->orWhereIn('dataset', TsePoliticalDataSyncService::YEARLESS_DATASETS))
            ->latest('id')
            ->get()
            ->unique('dataset')
            ->keyBy('dataset');

        return array_map(fn (string $dataset): array => [
            'key' => $dataset,
            'requires_year' => TsePoliticalDataSyncService::datasetRequiresYear($dataset),
            'slug' => str_replace('{ano}', (string) $election->ano, GovnexApiDatasetCatalog::pattern($dataset)),
            'latest_sync' => $latest->get($dataset)?->toSummary(),
        ], $keys);
    }

    /**
     * Põe na fila a sincronização de um dataset para o ano da eleição. Uma
     * execução ativa por dataset/ano: um lock curto serializa pedidos
     * simultâneos, e quem chega com outra já ativa é recusado.
     *
     * @throws ElectionLifecycleException
     */
    public function syncDataset(Election $election, string $dataset, User $requester): TseSync
    {
        if (! in_array($dataset, TsePoliticalDataSyncService::datasetsFor($election->tipo), true)) {
            throw new ElectionLifecycleException(
                "Este dataset não se aplica a {$election->tipo->label()}.",
                'dataset',
            );
        }

        // Datasets sem ano (base de municípios) seguem registrados no ano
        // corrente, como sempre foram.
        $year = TsePoliticalDataSyncService::datasetRequiresYear($dataset) ? $election->ano : now()->year;

        try {
            $this->datasets->assertDatasetPrerequisites($dataset, $year);
        } catch (RuntimeException $exception) {
            throw new ElectionLifecycleException($exception->getMessage(), 'dataset');
        }

        try {
            $run = Cache::lock('tse:enqueue:'.TsePoliticalDataSyncService::datasetHistoryKey($dataset, $year), 15)
                ->block(5, function () use ($dataset, $year, $requester): ?TseSync {
                    if ($this->hasActiveRun($dataset, $year)) {
                        return null;
                    }

                    $run = TseSync::query()->create([
                        'gabinete_id' => null,
                        'solicitado_por_id' => $requester->id,
                        'dataset' => $dataset,
                        'ano' => $year,
                        'fonte_url' => app(GovnexApiSettings::class)->url(),
                        'situacao' => 'pendente',
                        'iniciada_em' => now(),
                    ]);

                    try {
                        SyncDatasetFromGovnexApi::dispatch($run->id);
                    } catch (Throwable $exception) {
                        $run->update([
                            'situacao' => 'falhou',
                            'erro' => 'Não foi possível adicionar a sincronização à fila: '.$exception->getMessage(),
                            'concluida_em' => now(),
                        ]);

                        throw $exception;
                    }

                    return $run;
                });
        } catch (LockTimeoutException) {
            throw new ElectionLifecycleException(
                'Outra solicitação deste dataset está sendo registrada. Tente novamente em alguns segundos.',
            );
        }

        return $run ?? throw new ElectionLifecycleException(
            'Este dataset já possui uma sincronização em andamento ou travada. Cancele-a antes de tentar de novo.',
        );
    }

    /**
     * Cancela uma execução pendente ou em processamento — o job em curso a
     * interrompe de forma cooperativa. Só alcança execuções globais dos
     * datasets desta eleição.
     *
     * @throws ElectionLifecycleException
     */
    public function cancelSync(Election $election, TseSync $sync, User $requester): void
    {
        if (! $this->belongsTo($election, $sync)) {
            throw new ElectionLifecycleException('Sincronização não encontrada para esta eleição.', 'sync');
        }

        if (! in_array($sync->situacao, ['pendente', 'processando'], true)) {
            throw new ElectionLifecycleException('Só é possível cancelar sincronizações pendentes ou em processamento.');
        }

        $sync->update([
            'situacao' => 'cancelada',
            'erro' => "Cancelada manualmente por {$requester->name}.",
            'progresso_etapa' => null,
            'progresso_percentual' => null,
            'concluida_em' => now(),
        ]);
    }

    public function belongsTo(Election $election, TseSync $sync): bool
    {
        return $sync->gabinete_id === null
            && in_array($sync->dataset, TsePoliticalDataSyncService::datasetsFor($election->tipo), true)
            && (! TsePoliticalDataSyncService::datasetRequiresYear($sync->dataset) || $sync->ano === $election->ano);
    }

    // ---- Apuração --------------------------------------------------------

    public function canTally(Election $election): bool
    {
        return ! $election->primeiro_turno_em->isAfter(today());
    }

    /**
     * Põe a apuração da eleição na fila `tse` (TallyElection → tally()).
     *
     * @throws ElectionLifecycleException
     */
    public function requestTally(Election $election): void
    {
        if (! $this->canTally($election)) {
            throw new ElectionLifecycleException(
                'O 1º turno desta eleição ainda não aconteceu — não há apuração para buscar.',
            );
        }

        TallyElection::dispatch($election->id);
    }

    /**
     * Apura a eleição inteira, em três etapas que dependem umas das outras:
     *
     * 1. grava o 1º turno de toda disputa do feed do TSE — presidente,
     *    governador, senador e deputados —, inclusive as que se decidem ali;
     * 2. classifica para o 2º turno presidente e governador (a partir do
     *    gravado) e prefeito (da votação nominal sincronizada);
     * 3. grava o 2º turno das disputas classificadas.
     *
     * Idempotente: disputa já gravada ou classificada para em consultas
     * locais, sem ir ao TSE — repetir o disparo é seguro.
     *
     * @return array{recorded: int, classified: int}
     */
    public function tally(Election $election): array
    {
        $recorded = $this->results->recordElection($election, 1);
        $classified = $this->secondRound->classifyElection($election);
        $recorded += $this->results->recordElection($election, 2);

        return ['recorded' => $recorded, 'classified' => $classified];
    }

    /**
     * Mesma apuração, para quem já leu o feed do TSE de um turno numa UF (a
     * tela de apuração ao vivo): aproveita a leitura em vez de voltar ao TSE.
     *
     * @param  array<string, array<string, mixed>|null>  $raw  resultado por cargo do feed
     */
    public function recordLiveTally(Election $election, string $uf, int $round, array $raw): void
    {
        foreach (array_keys(ElectionResultRecorder::OFFICES) as $office) {
            $result = $raw[$office] ?? null;

            if ($result === null) {
                continue;
            }

            $round === 1 && in_array($office, ElectionResultRecorder::MAJORITY_OFFICES, true)
                ? $this->secondRound->classify($election, $office, $uf, null, $result)
                : $this->results->record($election, $office, $uf, $round, $result);
        }
    }

    /**
     * Situação da apuração por cargo da eleição: candidaturas sincronizadas,
     * quantas já têm resultado gravado e quantas foram eleitas.
     *
     * @return list<array{office: string, label: string, source: string, candidates: int, with_result: int, elected: int, updated_at: string|null}>
     */
    public function tallySummary(Election $election): array
    {
        $candidates = Candidate::query()
            ->where('eleicao_id', $election->id)
            ->toBase()
            ->selectRaw('UPPER(cargo) as office, COUNT(*) as total')
            ->groupByRaw('UPPER(cargo)')
            ->pluck('total', 'office');

        $tse = $this->resultCounts((new CandidateElectionResult)->getTable(), $election);
        $municipal = $this->resultCounts((new CandidateMunicipalityVote)->getTable(), $election);

        $summary = [];

        foreach (self::RESULT_OFFICES as $office => $meta) {
            $total = (int) ($candidates[$office] ?? 0);

            if ($total === 0) {
                continue;
            }

            $counts = ($meta['source'] === 'tse' ? $tse : $municipal)[$office] ?? null;

            $summary[] = [
                'office' => $office,
                'label' => $meta['label'],
                'source' => $meta['source'],
                'candidates' => $total,
                'with_result' => (int) ($counts->with_result ?? 0),
                'elected' => (int) ($counts->elected ?? 0),
                'updated_at' => isset($counts->updated_at) ? Carbon::parse($counts->updated_at)->toIso8601String() : null,
            ];
        }

        return $summary;
    }

    /**
     * Por cargo: candidaturas com resultado, eleitas e a gravação mais
     * recente, numa das duas tabelas de resultado (mesmas colunas
     * `candidato_politico_id`, `eleicao_id`, `eleito`).
     *
     * @return array<string, object>
     */
    private function resultCounts(string $table, Election $election): array
    {
        return Candidate::query()
            ->toBase()
            ->join("{$table} as r", 'r.candidato_politico_id', '=', 'candidatos_politicos.id')
            ->where('candidatos_politicos.eleicao_id', $election->id)
            ->whereNotNull('r.situacao_totalizacao')
            ->selectRaw('UPPER(candidatos_politicos.cargo) as office')
            ->selectRaw('COUNT(DISTINCT r.candidato_politico_id) as with_result')
            ->selectRaw('COUNT(DISTINCT CASE WHEN r.eleito = ? THEN r.candidato_politico_id END) as elected', [true])
            ->selectRaw('MAX(r.updated_at) as updated_at')
            ->groupByRaw('UPPER(candidatos_politicos.cargo)')
            ->get()
            ->keyBy('office')
            ->all();
    }

    private function hasActiveRun(string $dataset, int $year): bool
    {
        return TseSync::query()
            ->whereNull('gabinete_id')
            ->where('dataset', $dataset)
            ->when(
                TsePoliticalDataSyncService::datasetRequiresYear($dataset),
                fn ($query) => $query->where('ano', $year),
            )
            ->whereIn('situacao', ['pendente', 'processando'])
            ->exists();
    }
}
