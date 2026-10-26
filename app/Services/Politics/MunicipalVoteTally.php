<?php

namespace App\Services\Politics;

use App\Enums\CandidateScope;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\VotacaoCandidatoMunicipio;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Apuração de prefeito e vereador de um município, lida da votação nominal
 * já sincronizada (`votacoes_candidatos_municipio`, dataset
 * `candidate_votes`) — o feed de divulgação do TSE lido pelo
 * TseResultsClient não publica cargos municipais (ver ElectionResultRecorder).
 *
 * Devolve cada cargo no mesmo formato cru de TseResultsClient::results()
 * (`candidates` com number, ballot_name, party, votes, vote_percent,
 * elected), para a tela de apuração montar os cards e cruzar favoritos do
 * mesmo jeito que faz com presidente e governador. Diferenças assumidas:
 *
 * - `section_percent` é sempre null: a votação nominal não traz progresso de
 *   totalização por seção, e inventar um número seria enganoso.
 * - `finished` vem de `situacao_totalizacao`: a disputa está fechada quando
 *   toda linha dela já tem a situação totalizada gravada.
 * - "Vai ao 2º turno" vem de `candidatos_politicos.avancou_segundo_turno`,
 *   gravado pelo SecondRoundClassifier — a regra não é refeita aqui.
 * - Vereador: o percentual é sobre a soma dos votos nominais da disputa; o
 *   oficial do TSE também conta voto de legenda, que esta fonte não tem.
 *
 * É dado público da eleição; nada aqui depende de gabinete.
 */
class MunicipalVoteTally
{
    /** Cargo da apuração => `cargo` do TSE em `candidatos_politicos`. */
    public const OFFICES = [
        'prefeito' => 'PREFEITO',
        'vereador' => 'VEREADOR',
    ];

    /**
     * @return array<string, array{section_percent: null, total_sections: null, finished: bool, goes_to_second_round: bool, candidates: array<int, array<string, mixed>>}|null>
     */
    public function results(Eleicao $election, int $municipalityId, int $round): array
    {
        $candidates = CandidatoPolitico::query()
            ->where('eleicao_id', $election->id)
            ->where('abrangencia', CandidateScope::Municipal)
            ->where('municipio_eleitoral_id', $municipalityId)
            ->whereRaw('UPPER(cargo) IN (?, ?)', array_values(self::OFFICES))
            ->get(['id', 'cargo', 'nome', 'nome_urna', 'numero', 'partido_sigla', 'avancou_segundo_turno']);

        $votes = VotacaoCandidatoMunicipio::query()
            ->where('eleicao_id', $election->id)
            ->where('municipio_eleitoral_id', $municipalityId)
            ->whereIn('candidato_politico_id', $candidates->modelKeys())
            ->get(['candidato_politico_id', 'turno', 'votos_nominais', 'votos_nominais_validos', 'situacao_totalizacao', 'eleito']);

        $candidates = $candidates->groupBy(fn (CandidatoPolitico $candidate): string => Str::upper(trim($candidate->cargo)));
        $results = [];

        foreach (self::OFFICES as $office => $tseOffice) {
            // Vereador não tem 2º turno: no 2º turno só a disputa de prefeito segue.
            if ($office === 'vereador' && $round === 2) {
                continue;
            }

            $race = $candidates->get($tseOffice, collect());
            $results[$office] = $race->isEmpty()
                ? null
                : $this->race($office, $race, $votes, $round, $election->segundo_turno_em !== null);
        }

        return $results;
    }

    /**
     * @param  Collection<int, CandidatoPolitico>  $race
     * @param  Collection<int, VotacaoCandidatoMunicipio>  $votes
     * @return array{section_percent: null, total_sections: null, finished: bool, goes_to_second_round: bool, candidates: array<int, array<string, mixed>>}
     */
    private function race(string $office, Collection $race, Collection $votes, int $round, bool $electionHasSecondRound): array
    {
        $byId = $race->keyBy('id');
        $raceVotes = $votes->whereIn('candidato_politico_id', $byId->keys()->all());
        $advanced = $race->where('avancou_segundo_turno', true);
        $shownRound = $round;

        if ($round === 2 && $office === 'prefeito' && $advanced->isEmpty()) {
            // Sem classificados, a disputa se decidiu no 1º turno (ou ainda
            // não houve 1º turno): o resultado que vale é o dele.
            $shownRound = 1;
        }

        $roundVotes = $raceVotes->where('turno', $shownRound)->values();

        if ($roundVotes->isEmpty()) {
            // Antes da votação nominal chegar, mostra quem está concorrendo
            // com votos zerados — no 2º turno, só os classificados.
            $roster = $shownRound === 2 ? $advanced : $race;

            return [
                'section_percent' => null,
                'total_sections' => null,
                'finished' => false,
                'goes_to_second_round' => false,
                'candidates' => $roster
                    ->sortBy('nome_urna')
                    ->map(fn (CandidatoPolitico $candidate): array => $this->candidate($candidate, 0, 0.0, false))
                    ->values()
                    ->all(),
            ];
        }

        $validTotal = (int) $roundVotes->sum('votos_nominais_validos');

        return [
            'section_percent' => null,
            'total_sections' => null,
            'finished' => $roundVotes->every(fn (VotacaoCandidatoMunicipio $vote): bool => $vote->situacao_totalizacao !== null),
            'goes_to_second_round' => $office === 'prefeito'
                && $electionHasSecondRound
                && $round === 1
                && $advanced->isNotEmpty(),
            'candidates' => $roundVotes
                ->sortByDesc('votos_nominais')
                ->map(fn (VotacaoCandidatoMunicipio $vote): array => $this->candidate(
                    $byId->get($vote->candidato_politico_id),
                    $vote->votos_nominais,
                    $validTotal > 0 ? round($vote->votos_nominais_validos / $validTotal * 100, 2) : 0.0,
                    $vote->eleito,
                ))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function candidate(CandidatoPolitico $candidate, int $votes, float $percent, bool $elected): array
    {
        return [
            'number' => (string) $candidate->numero,
            'name' => $candidate->nome,
            'ballot_name' => $candidate->nome_urna,
            'party' => (string) $candidate->partido_sigla,
            'coalition' => null,
            'votes' => $votes,
            'vote_percent' => $percent,
            'elected' => $elected,
        ];
    }
}
