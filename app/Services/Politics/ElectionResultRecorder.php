<?php

namespace App\Services\Politics;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\ResultadoCandidatoEleicao;
use App\Services\Politics\Tse\TseResultsClient;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grava em `resultados_candidatos_eleicao` o resultado final, por turno, de
 * toda disputa que a apuração ao vivo do TSE (TseResultsClient) cobre — o
 * mesmo feed que a tela de apuração já lê. Prefeito e vereador não passam
 * por aqui: o resultado deles vem da votação nominal sincronizada
 * (`votacoes_candidatos_municipio`, dataset `candidate_votes`).
 *
 * "Disputa" é eleição + cargo + território (Brasil para presidente, UF para
 * os demais). A regra de quem está eleito depende do cargo:
 *
 * - majoritários com 2º turno possível (presidente, governador): "ELEITO"
 *   só com mais de 50% dos válidos; sem maioria, os classificados ficam em
 *   "2º TURNO" — a mesma leitura de ElectionTallyController::results();
 * - cadeiras (senador, que é majoritário sem 2º turno, e os proporcionais):
 *   vale a marcação `e:"s"` do TSE, como na contagem de cadeiras da tela de
 *   apuração (ElectionTallyController::seatsByParty()). O TSE já resolve o
 *   quociente partidário e as sobras; a situação vem do próprio feed
 *   ("ELEITO POR QP", "ELEITO POR MÉDIA", "SUPLENTE"…) quando publicada.
 *
 * Grava uma única vez por disputa e turno, só com 100% das seções apuradas,
 * e não volta ao TSE depois de gravado.
 */
class ElectionResultRecorder
{
    /** Cargo do TSE (`DS_CARGO`, maiúsculo) por chave de cargo do feed. */
    public const OFFICES = [
        'presidente' => 'PRESIDENTE',
        'governador' => 'GOVERNADOR',
        'senador' => 'SENADOR',
        'deputado_federal' => 'DEPUTADO FEDERAL',
        'deputado_estadual' => 'DEPUTADO ESTADUAL',
        'deputado_distrital' => 'DEPUTADO DISTRITAL',
    ];

    /** Maioria absoluta, com 2º turno possível. */
    public const MAJORITY_OFFICES = ['presidente', 'governador'];

    /** Situações do TSE reconhecidas para os cargos de cadeira. */
    private const SEAT_STATUSES = [
        'ELEITO', 'ELEITO POR QP', 'ELEITO POR MÉDIA', 'SUPLENTE', 'NÃO ELEITO',
    ];

    public function __construct(
        private readonly TseResultsClient $tseResults,
    ) {}

    /**
     * Candidaturas de uma disputa. `$uf` é ignorado para presidente.
     *
     * @return Builder<CandidatoPolitico>
     */
    public function raceQuery(Eleicao $election, string $office, ?string $uf): Builder
    {
        $query = CandidatoPolitico::query()
            ->where('eleicao_id', $election->id)
            ->whereRaw('UPPER(cargo) = ?', [self::OFFICES[$office]]);

        return $office === 'presidente'
            ? $query->where('abrangencia', CandidateScope::National)
            : $query
                ->where('abrangencia', CandidateScope::State)
                ->where('uf', mb_strtoupper((string) $uf));
    }

    /**
     * Grava o resultado de um turno de uma disputa.
     *
     * O 2º turno só existe para os majoritários e só depois de a disputa ter
     * classificados gravados (SecondRoundClassifier). `$tseResult` é
     * opcional: quem já tem a apuração em mãos (a tela de apuração) a repassa
     * e evita outra leitura do TSE. Devolve true só quando gravou agora.
     *
     * @param  array<string, mixed>|null  $tseResult
     */
    public function record(
        Eleicao $election,
        string $office,
        ?string $uf,
        int $round,
        ?array $tseResult = null,
    ): bool {
        if (! isset(self::OFFICES[$office]) || ($office !== 'presidente' && $uf === null)) {
            return false;
        }

        $majority = in_array($office, self::MAJORITY_OFFICES, true);

        if ($round === 2 && ! $majority) {
            return false;
        }

        $roundDate = $round === 2 ? $election->segundo_turno_em : $election->primeiro_turno_em;

        if ($roundDate === null || $roundDate->isAfter(today())) {
            return false;
        }

        $race = $this->raceQuery($election, $office, $uf);

        // Sem candidatura local da disputa não há o que gravar — e nem por
        // que ir ao TSE (ex.: presidente numa eleição municipal).
        if (! (clone $race)->exists()) {
            return false;
        }

        if ($round === 2 && ! (clone $race)->where('avancou_segundo_turno', true)->exists()) {
            return false;
        }

        $alreadyRecorded = ResultadoCandidatoEleicao::query()
            ->where('turno', $round)
            ->whereIn('candidato_politico_id', (clone $race)->select('id'))
            ->exists();

        if ($alreadyRecorded) {
            return false;
        }

        $tseResult ??= $this->tseResults->results(
            $office,
            $office === 'presidente' ? 'BR' : (string) $uf,
            $election->primeiro_turno_em->toDateString(),
            $round,
        );

        if ($tseResult === null || (float) ($tseResult['section_percent'] ?? 0) < 100) {
            return false;
        }

        /** @var list<array<string, mixed>> $candidates */
        $candidates = array_values($tseResult['candidates'] ?? []);
        $statuses = $majority
            ? $this->majorityStatuses($candidates, $round, $election->segundo_turno_em !== null)
            : $this->seatStatuses($candidates);

        if ($statuses === []) {
            return false;
        }

        $ids = (clone $race)
            ->whereIn('numero', array_keys($statuses))
            ->pluck('id', 'numero');

        if ($ids->isEmpty()) {
            return false;
        }

        $now = now();
        $rows = collect($candidates)
            ->filter(fn (array $candidate): bool => $ids->has($candidate['number']))
            ->map(function (array $candidate) use ($ids, $statuses, $election, $round, $now): array {
                [$status, $elected] = $statuses[(string) $candidate['number']];

                return [
                    'candidato_politico_id' => $ids[$candidate['number']],
                    'eleicao_id' => $election->id,
                    'turno' => $round,
                    'votos' => (int) $candidate['votes'],
                    'percentual_validos' => round((float) $candidate['vote_percent'], 2),
                    'situacao_totalizacao' => $status,
                    'eleito' => $elected,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();

        ResultadoCandidatoEleicao::query()->upsert(
            $rows,
            ['candidato_politico_id', 'turno'],
            ['votos', 'percentual_validos', 'situacao_totalizacao', 'eleito', 'updated_at'],
        );

        return true;
    }

    /**
     * Grava um turno de todas as disputas da eleição que ainda não têm
     * resultado — presidente uma vez, os demais por UF com candidatura
     * sincronizada. Disputas já gravadas param em consultas locais, sem ir
     * ao TSE. Devolve quantas disputas foram gravadas agora.
     */
    public function recordElection(Eleicao $election, int $round): int
    {
        $recorded = 0;

        foreach ($this->races($election) as [$office, $uf]) {
            $recorded += (int) $this->record($election, $office, $uf, $round);
        }

        return $recorded;
    }

    /**
     * Disputas da eleição pelo feed do TSE: presidente (Brasil) e, para cada
     * cargo estadual, as UFs com candidatura sincronizada.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function races(Eleicao $election): array
    {
        $statewide = array_diff_key(self::OFFICES, ['presidente' => true]);
        $races = [['presidente', 'BR']];

        $placeholders = implode(', ', array_fill(0, count($statewide), '?'));
        $rows = CandidatoPolitico::query()
            ->where('eleicao_id', $election->id)
            ->where('abrangencia', CandidateScope::State)
            ->whereNotNull('uf')
            ->whereRaw("UPPER(cargo) IN ({$placeholders})", array_values($statewide))
            ->toBase()
            ->selectRaw('UPPER(cargo) as cargo_tse, uf')
            ->distinct()
            ->orderBy('cargo_tse')
            ->orderBy('uf')
            ->get();

        foreach ($rows as $row) {
            $office = array_search($row->cargo_tse, $statewide, true);

            if (is_string($office)) {
                $races[] = [$office, (string) $row->uf];
            }
        }

        return $races;
    }

    /**
     * Presidente e governador. No 1º turno o TSE marca `e:"s"` também nos
     * dois classificados para o 2º — "eleito" de verdade só com mais de 50%.
     *
     * @param  list<array<string, mixed>>  $candidates  ordenados por votos
     * @return array<string, array{0: string, 1: bool}>
     */
    private function majorityStatuses(array $candidates, int $round, bool $hasSecondRound): array
    {
        $flagged = array_values(array_filter($candidates, fn (array $candidate): bool => (bool) $candidate['elected']));
        $majority = array_values(array_filter($candidates, fn (array $candidate): bool => (float) $candidate['vote_percent'] > 50));

        [$winners, $winnerStatus] = match (true) {
            $round === 1 && $majority !== [] => [$majority, ResultadoCandidatoEleicao::ELECTED],
            // Sem maioria: os classificados; se a marcação não vier, os dois
            // mais votados.
            $round === 1 && $hasSecondRound => [$flagged ?: array_slice($candidates, 0, 2), ResultadoCandidatoEleicao::SECOND_ROUND],
            default => [$flagged ?: array_slice($candidates, 0, 1), ResultadoCandidatoEleicao::ELECTED],
        };

        $winnerNumbers = array_column($winners, 'number');
        $statuses = [];

        foreach ($candidates as $candidate) {
            $winner = in_array($candidate['number'], $winnerNumbers, true);
            $status = $winner ? $winnerStatus : ResultadoCandidatoEleicao::NOT_ELECTED;
            $statuses[(string) $candidate['number']] = [$status, $status === ResultadoCandidatoEleicao::ELECTED];
        }

        return $statuses;
    }

    /**
     * Senador e deputados: eleito é quem o TSE marca `e:"s"`. Sem nenhuma
     * marcação a totalização ainda não fechou — não grava nada (vazio), em vez
     * de adivinhar quantas cadeiras a UF tem.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, array{0: string, 1: bool}>
     */
    private function seatStatuses(array $candidates): array
    {
        if (! collect($candidates)->contains(fn (array $candidate): bool => (bool) $candidate['elected'])) {
            return [];
        }

        $statuses = [];

        foreach ($candidates as $candidate) {
            $elected = (bool) $candidate['elected'];
            $published = mb_strtoupper(trim((string) ($candidate['status'] ?? '')));
            // A situação publicada só vale se combinar com a marcação.
            $consistent = in_array($published, self::SEAT_STATUSES, true)
                && str_starts_with($published, 'ELEITO') === $elected;

            $statuses[(string) $candidate['number']] = [
                $consistent ? $published : ($elected ? ResultadoCandidatoEleicao::ELECTED : ResultadoCandidatoEleicao::NOT_ELECTED),
                $elected,
            ];
        }

        return $statuses;
    }
}
