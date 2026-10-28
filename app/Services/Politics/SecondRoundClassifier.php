<?php

namespace App\Services\Politics;

use App\Enums\CandidateScope;
use App\Models\Candidate;
use App\Models\CandidateElectionResult;
use App\Models\CandidateMunicipalityVote;
use App\Models\Election;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grava em `candidatos_politicos.avancou_segundo_turno` quem foi ao 2º turno
 * de uma disputa majoritária, uma única vez, quando o 1º turno termina sem
 * vencedor. Depois disso o painel político passa a mostrar só os
 * classificados e as pesquisas de 2º turno daquela disputa.
 *
 * "Disputa" é eleição + cargo + território (Brasil para presidente, UF para
 * governador, município para prefeito). É dado público da eleição,
 * compartilhado por todos os gabinetes da região — nada aqui depende de
 * gabinete.
 *
 * Só classifica; gravar o resultado de cada turno é do
 * ElectionResultRecorder, e a ordem das etapas de uma apuração inteira fica
 * no ElectionLifecycleService. Fontes de "quem avançou":
 * - presidente e governador: o resultado do 1º turno já gravado em
 *   `resultados_candidatos_eleicao` ("2º TURNO" na situação);
 * - prefeito: a votação nominal já sincronizada em
 *   `votacoes_candidatos_municipio` — `situacao_totalizacao` "2º TURNO" no
 *   1º turno ou, depois do 2º turno, as linhas de turno 2.
 */
class SecondRoundClassifier
{
    /** Disputas majoritárias com 2º turno possível, pelo `cargo` do TSE. */
    public const OFFICES = [
        'presidente' => 'PRESIDENTE',
        'governador' => 'GOVERNADOR',
        'prefeito' => 'PREFEITO',
    ];

    public function __construct(
        private readonly ElectionResultRecorder $results,
    ) {}

    /**
     * Candidaturas de uma disputa. `$uf` só importa para governador e
     * `$municipalityId` só para prefeito.
     *
     * @return Builder<Candidate>
     */
    public function raceQuery(Election $election, string $office, ?string $uf, ?int $municipalityId): Builder
    {
        if ($office !== 'prefeito') {
            return $this->results->raceQuery($election, $office, $uf);
        }

        return Candidate::query()
            ->where('eleicao_id', $election->id)
            ->whereRaw('UPPER(cargo) = ?', [self::OFFICES['prefeito']])
            ->where('abrangencia', CandidateScope::Municipal)
            ->where('municipio_eleitoral_id', $municipalityId);
    }

    /**
     * Turno que o painel deve mostrar para a disputa: 2 quando já há
     * classificados gravados, 1 em qualquer outro caso (inclusive cargos sem
     * 2º turno, como senador).
     */
    public function activeRound(Election $election, string $office, ?string $uf, ?int $municipalityId): int
    {
        if (! isset(self::OFFICES[$office]) || ($office === 'prefeito' && $municipalityId === null)) {
            return 1;
        }

        return $this->raceQuery($election, $office, $uf, $municipalityId)
            ->where('avancou_segundo_turno', true)
            ->exists() ? 2 : 1;
    }

    /**
     * Ponto único de classificação de uma disputa — presidente, governador e
     * prefeito passam por aqui, com a mesma regra: na primeira vez em que o
     * 1º turno aparece concluído sem vencedor, grava a disputa inteira
     * (classificados em true, eliminados em false). Só a fonte de "quem
     * avançou" muda por cargo (ver advancedFromRecordedResults() e
     * advancedFromMunicipalVotes()).
     *
     * `$tseResult` é opcional: quem já tem o resultado do 1º turno em mãos (a
     * tela de apuração) o repassa e evita outra leitura do TSE. Devolve true
     * só quando gravou a classificação agora.
     *
     * @param  array<string, mixed>|null  $tseResult
     */
    public function classify(
        Election $election,
        string $office,
        ?string $uf,
        ?int $municipalityId,
        ?array $tseResult = null,
    ): bool {
        if (! isset(self::OFFICES[$office])
            || ($office === 'prefeito' && $municipalityId === null)
            || ($office === 'governador' && $uf === null)) {
            return false;
        }

        // Presidente e governador: a classificação sai do resultado do 1º
        // turno gravado, então ele é gravado antes (sem custo quando já está).
        if ($office !== 'prefeito') {
            $this->results->record($election, $office, $uf, 1, $tseResult);
        }

        if ($election->segundo_turno_em === null) {
            return false;
        }

        $race = $this->raceQuery($election, $office, $uf, $municipalityId);

        if ((clone $race)->whereNotNull('avancou_segundo_turno')->exists()) {
            return false;
        }

        $advancedIds = $office === 'prefeito'
            ? $this->advancedFromMunicipalVotes($election, (int) $municipalityId, $race)
            : $this->advancedFromRecordedResults($race);

        return $this->persist($race, $advancedIds);
    }

    /**
     * Classifica todas as disputas ainda pendentes de uma eleição; devolve
     * quantas foram classificadas para o 2º turno agora.
     */
    public function classifyElection(Election $election): int
    {
        $classified = 0;
        $races = Candidate::query()
            ->where('eleicao_id', $election->id)
            ->whereRaw('UPPER(cargo) = ?', [self::OFFICES['governador']])
            ->whereNotNull('uf')
            ->distinct()
            ->pluck('uf')
            ->map(fn (string $uf): array => ['governador', $uf])
            ->prepend(['presidente', 'BR']);

        // Cada passo para sozinho quando a disputa já está gravada, então
        // revisitar as já resolvidas custa só consultas locais.
        foreach ($races as [$office, $uf]) {
            $classified += (int) $this->classify($election, $office, $uf, null);
        }

        if ($election->segundo_turno_em === null) {
            return $classified;
        }

        // Só os municípios com algum sinal de 2º turno — a grande maioria se
        // decide no 1º e não precisa ser revisitada a cada execução.
        $municipalities = CandidateMunicipalityVote::query()
            ->where('eleicao_id', $election->id)
            ->whereIn('candidato_politico_id', $this->pending($election, 'prefeito')->select('id'))
            ->where(fn (Builder $query) => $query
                ->where('turno', 2)
                ->orWhere('situacao_totalizacao', 'like', '2%TURNO%'))
            ->distinct()
            ->pluck('municipio_eleitoral_id');

        foreach ($municipalities as $municipalityId) {
            $classified += (int) $this->classify($election, 'prefeito', null, (int) $municipalityId);
        }

        return $classified;
    }

    /**
     * Presidente e governador: os classificados vêm do resultado do 1º turno
     * já gravado pelo ElectionResultRecorder — "2º TURNO" na situação.
     *
     * @param  Builder<Candidate>  $race
     * @return Collection<int, mixed>
     */
    private function advancedFromRecordedResults(Builder $race): Collection
    {
        return CandidateElectionResult::query()
            ->where('turno', 1)
            ->where('situacao_totalizacao', CandidateElectionResult::SECOND_ROUND)
            ->whereIn('candidato_politico_id', (clone $race)->select('id'))
            ->pluck('candidato_politico_id');
    }

    /**
     * Prefeito: votação nominal já sincronizada do TSE. No 1º turno, o
     * DS_SIT_TOT_TURNO dos classificados é "2º TURNO"; depois do 2º turno,
     * quem tem votação de turno 2 é quem disputou.
     *
     * @param  Builder<Candidate>  $race
     * @return Collection<int, mixed>
     */
    private function advancedFromMunicipalVotes(Election $election, int $municipalityId, Builder $race): Collection
    {
        $votes = CandidateMunicipalityVote::query()
            ->where('eleicao_id', $election->id)
            ->where('municipio_eleitoral_id', $municipalityId)
            ->whereIn('candidato_politico_id', (clone $race)->select('id'))
            ->get(['candidato_politico_id', 'turno', 'situacao_totalizacao']);

        $advanced = $votes->where('turno', 2)->pluck('candidato_politico_id');

        if ($advanced->isEmpty()) {
            $advanced = $votes
                ->where('turno', 1)
                ->filter(fn (CandidateMunicipalityVote $vote): bool => $this->marksSecondRound($vote->situacao_totalizacao))
                ->pluck('candidato_politico_id');
        }

        return $advanced->unique()->values();
    }

    /** @return Builder<Candidate> */
    private function pending(Election $election, string $office): Builder
    {
        return Candidate::query()
            ->where('eleicao_id', $election->id)
            ->whereRaw('UPPER(cargo) = ?', [self::OFFICES[$office]])
            ->whereNull('avancou_segundo_turno');
    }

    /**
     * Grava a disputa inteira de uma vez: eliminados em false, classificados
     * em true. Sem nenhum classificado reconhecido localmente não grava nada —
     * melhor seguir mostrando todos do que esconder a disputa inteira.
     *
     * @param  Builder<Candidate>  $race
     * @param  Collection<int, mixed>  $advancedIds
     */
    private function persist(Builder $race, Collection $advancedIds): bool
    {
        if ($advancedIds->isEmpty()) {
            return false;
        }

        DB::transaction(function () use ($race, $advancedIds): void {
            (clone $race)->update(['avancou_segundo_turno' => false]);
            Candidate::query()
                ->whereKey($advancedIds->all())
                ->update(['avancou_segundo_turno' => true]);
        });

        return true;
    }

    /** "2º TURNO" no DS_SIT_TOT_TURNO do TSE, em qualquer grafia. */
    private function marksSecondRound(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', Str::ascii(mb_strtoupper($status))) ?? '';

        return str_starts_with($normalized, '2') && str_contains($normalized, 'TURNO');
    }
}
