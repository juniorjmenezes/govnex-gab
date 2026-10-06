<?php

namespace App\Http\Controllers;

use App\Models\CandidatoFavorito;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\PartidoCor;
use App\Models\User;
use App\Services\Politics\Tse\TseResultsClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ElectionTallyController extends Controller
{
    /**
     * Horário oficial de votação no Brasil: 08h às 17h, sem intervalo.
     */
    private const VOTING_START_HOUR = 8;

    private const VOTING_END_HOUR = 17;

    /**
     * Cargos mostrados na apuração: as eleições gerais não elegem vereador
     * (isso ocorre nas municipais, acompanhadas no painel político comum) —
     * o que o eleitorado do gabinete vota aqui é a chapa nacional e a
     * estadual da sua própria UF.
     *
     * @var array<string, string>
     */
    private const CARGOS = [
        'presidente' => 'Presidente',
        'governador' => 'Governador',
        'senador' => 'Senador',
        'deputado_federal' => 'Deputado Federal',
        'deputado_estadual' => 'Deputado Estadual',
    ];

    /** Cargos proporcionais têm dezenas de candidatos por UF — mostra só os
     * mais votados, com a contagem do restante. */
    private const CANDIDATE_LIMIT = 5;

    /** Cargos que elegem cadeiras (e não um único vencedor). */
    private const SEAT_CARGOS = ['senador', 'deputado_federal', 'deputado_estadual'];

    /** Total da casa para os cargos de bancada nacional. A Assembleia
     * estadual não entra aqui: o gráfico dela mostra só as cadeiras do estado. */
    private const CHAMBER_TOTALS = ['senador' => 81, 'deputado_federal' => 513];

    /**
     * `CandidatoPolitico::cargo` guarda o `DS_CARGO` do TSE como veio na
     * importação (ex.: "Presidente", mas também já visto em minúsculas em
     * dados antigos) — normaliza em maiúsculas para casar com o cargo da
     * apuração, sem depender de uma grafia exata.
     *
     * @var array<string, string>
     */
    private const CARGO_BY_OFFICE_LABEL = [
        'PRESIDENTE' => 'presidente',
        'GOVERNADOR' => 'governador',
        'SENADOR' => 'senador',
        'DEPUTADO FEDERAL' => 'deputado_federal',
        'DEPUTADO ESTADUAL' => 'deputado_estadual',
    ];

    public function __construct(
        private readonly TseResultsClient $tseResults,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);

        $office = Gabinete::query()->findOrFail($user->gabinete_id);
        $timezone = $office->timezone ?? 'America/Sao_Paulo';
        $today = CarbonImmutable::now($timezone)->toDateString();

        $rounds = $this->rounds();
        // Prioriza o turno que cai hoje; sem um, mostra o próximo turno
        // futuro para dar contexto; sem nenhum futuro, o último já ocorrido.
        // Turno escolhido na tela (?data=YYYY-MM-DD) tem prioridade; sem
        // escolha, o que cai hoje, depois o próximo futuro, e por último o
        // mais recente já ocorrido.
        $selected = $rounds->firstWhere('date', $request->string('data')->toString())
            ?? $rounds->firstWhere('date', $today)
            ?? $rounds->filter(fn (array $round): bool => $round['date'] >= $today)->sortBy('date')->first()
            ?? $rounds->sortByDesc('date')->first();

        $round = null;
        $results = null;
        $favorites = null;
        $municipal = null;
        if ($selected !== null) {
            $windowStart = CarbonImmutable::parse($selected['date'], $timezone)
                ->setTime(self::VOTING_START_HOUR, 0);
            $windowEnd = CarbonImmutable::parse($selected['date'], $timezone)
                ->setTime(self::VOTING_END_HOUR, 0);

            $round = [
                'election_id' => $selected['election_id'],
                'label' => $selected['label'],
                'date' => $selected['date'],
                'is_today' => $selected['date'] === $today,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
                'round' => $selected['round'],
                'has_second_round' => $selected['has_second_round'],
            ];

            // O TSE publica a lista de candidatos (com votos zerados) desde
            // antes da votação fechar, não só depois das 17h — então a
            // busca não tem gate de horário; cada cargo fica `available:
            // false` sozinho se o arquivo realmente não existir ainda (ver
            // TseResultsClient::results()).
            $raw = $this->fetchResults($selected['pleito_date'], $selected['round'], $office->estado);
            $results = $this->results($raw, $selected['has_second_round'], $selected['round']);
            $favorites = $this->favorites($raw, $office, $selected['election_id']);
            $municipal = $this->municipal($office, $selected);
        }

        return Inertia::render('politics/apuracao', [
            'round' => $round,
            'rounds' => $rounds->map(fn (array $item): array => [
                'date' => $item['date'],
                'label' => $item['label'],
            ])->values()->all(),
            'results' => $results,
            'municipal' => $municipal,
            'uf' => $office->estado,
            'favorites' => $favorites,
            'serverNow' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Busca todos os cargos de uma vez, cru — base tanto da lista por cargo
     * quanto do card de favoritos, que precisa do candidato mesmo fora do
     * corte de {@see self::CANDIDATE_LIMIT}.
     *
     * @return array<string, array{section_percent: float, total_sections: int, counted_sections: int, candidates: array<int, array<string, mixed>>}|null>
     */
    private function fetchResults(string $pleitoDate, int $round, string $uf, ?string $municipality = null): array
    {
        return collect(self::CARGOS)
            ->keys()
            ->mapWithKeys(fn (string $cargo): array => [$cargo => $this->tseResults->results($cargo, $uf, $pleitoDate, $round, $municipality)])
            ->all();
    }

    /**
     * "Meu município": a mesma apuração por cargo, recortada pelos votos da
     * cidade do gabinete. Sem município vinculado, devolve null e a tela
     * pede a vinculação.
     *
     * @param  array<string, mixed>  $selected
     * @return array{name: string, section_percent: float|null, results: array<int, array<string, mixed>>, favorites: array<int, array<string, mixed>>}|null
     */
    private function municipal(Gabinete $office, array $selected): ?array
    {
        $code = $office->municipioEleitoral?->codigo_tse;

        if ($code === null || $code === '') {
            return null;
        }

        $raw = $this->fetchResults($selected['pleito_date'], $selected['round'], $office->estado, $code);
        // Turno do 2º turno é nacional/estadual: no município não há "vai ao 2º turno".
        $results = $this->results($raw, false, $selected['round']);
        $sectionPercent = collect($results)->firstWhere('section_percent', '!==', null)['section_percent'] ?? null;

        return [
            'name' => $office->municipio,
            'section_percent' => $sectionPercent,
            'results' => $results,
            'favorites' => $this->favorites($raw, $office, $selected['election_id']),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $raw
     * @return array<int, array{cargo: string, label: string, available: bool, section_percent: float|null, total_sections: int|null, candidates: array<int, array<string, mixed>>, hidden_candidates: int}>
     */
    private function results(array $raw, bool $electionHasSecondRound, int $round): array
    {
        return collect(self::CARGOS)
            ->map(function (string $label, string $cargo) use ($raw, $electionHasSecondRound, $round): array {
                $result = $raw[$cargo] ?? null;
                $candidates = $result['candidates'] ?? [];
                $sectionPercent = $result['section_percent'] ?? null;
                $finished = $sectionPercent !== null && $sectionPercent >= 100;
                $elected = array_values(array_filter($candidates, fn (array $c): bool => $c['elected']));

                return [
                    'cargo' => $cargo,
                    'label' => $label,
                    'available' => $result !== null,
                    'section_percent' => $sectionPercent,
                    'total_sections' => $result['total_sections'] ?? null,
                    // Top 5 na tela; a lista completa vai no botão "ver todos".
                    'candidates' => array_slice($candidates, 0, self::CANDIDATE_LIMIT),
                    'all_candidates' => $candidates,
                    'total_candidates' => count($candidates),
                    // Cargos de cadeira (Senado e proporcionais): cadeiras por partido.
                    'seats' => in_array($cargo, self::SEAT_CARGOS, true) ? $this->seatsByParty($elected) : null,
                    // 1º turno concluído sem eleito: a disputa vai ao 2º turno.
                    'goes_to_second_round' => $electionHasSecondRound && $round === 1 && $finished && $elected === [],
                    'finished' => $finished,
                    // Tamanho da casa inteira (cadeiras constitucionais): as
                    // que não são do estado do gabinete ficam em cinza.
                    'chamber_total' => self::CHAMBER_TOTALS[$cargo] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $elected
     * @return array<int, array{party: string, seats: int}>
     */
    private function seatsByParty(array $elected): array
    {
        $colors = PartidoCor::colorMap();

        return collect($elected)
            ->groupBy('party')
            ->map(fn (Collection $items, string $party): array => [
                'party' => $party,
                'seats' => $items->count(),
                // Mesma cor cadastrada pelo root em /admin/cores-partidos.
                'color' => $colors[PartidoCor::normalizeSigla($party)] ?? null,
            ])
            ->sortByDesc('seats')
            ->values()
            ->all();
    }

    /**
     * Cruza os favoritos do gabinete (Painel político) com a apuração ao
     * vivo pelo número de urna — fora do corte de {@see self::CANDIDATE_LIMIT},
     * porque um favorito pode estar mal colocado num cargo proporcional.
     *
     * @param  array<string, array<string, mixed>|null>  $raw
     * @return array<int, array{candidato_politico_id: int, name: string, party: string, number: string|null, cargo_label: string, found: bool, votes: int|null, vote_percent: float|null, elected: bool, section_percent: float|null}>
     */
    private function favorites(array $raw, Gabinete $office, int $electionId): array
    {
        return CandidatoFavorito::query()
            ->where('gabinete_id', $office->id)
            ->with('candidato')
            ->get()
            ->map(fn (CandidatoFavorito $favorite): ?CandidatoPolitico => $favorite->candidato)
            ->filter(fn (?CandidatoPolitico $candidate): bool => $candidate !== null && $candidate->eleicao_id === $electionId)
            ->map(function (CandidatoPolitico $candidate) use ($raw): ?array {
                $cargo = self::CARGO_BY_OFFICE_LABEL[Str::upper(trim($candidate->cargo))] ?? null;

                if ($cargo === null) {
                    return null;
                }

                $result = $raw[$cargo] ?? null;
                $match = collect($result['candidates'] ?? [])
                    ->first(fn (array $tse): bool => $tse['number'] === $candidate->numero);

                return [
                    'candidato_politico_id' => $candidate->id,
                    'name' => $candidate->nome_urna,
                    'party' => (string) $candidate->partido_sigla,
                    'number' => $candidate->numero,
                    'cargo_label' => self::CARGOS[$cargo],
                    'found' => $match !== null,
                    'votes' => $match['votes'] ?? null,
                    'vote_percent' => $match['vote_percent'] ?? null,
                    'elected' => $match['elected'] ?? false,
                    'section_percent' => $result['section_percent'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Um item por turno (1º e, quando existir, 2º) de cada eleição
     * cadastrada, mais novo primeiro — achatado para facilitar a escolha do
     * turno relevante em `index()`.
     *
     * @return Collection<int, array{election_id: int, label: string, date: string}>
     */
    private function rounds(): Collection
    {
        return Eleicao::query()
            ->orderByDesc('ano')
            ->get()
            ->flatMap(function (Eleicao $election): array {
                // `pleito_date` é a data do 1º turno: o TSE indexa o pleito
                // por ela, e o 2º turno é o mesmo pleito com outro código.
                $pleitoDate = $election->primeiro_turno_em->toDateString();
                $rounds = [
                    [
                        'election_id' => $election->id,
                        'label' => "{$election->nome} · 1º turno",
                        'date' => $pleitoDate,
                        'pleito_date' => $pleitoDate,
                        'round' => 1,
                        'has_second_round' => $election->segundo_turno_em !== null,
                    ],
                ];

                if ($election->segundo_turno_em !== null) {
                    $rounds[] = [
                        'election_id' => $election->id,
                        'label' => "{$election->nome} · 2º turno",
                        'date' => $election->segundo_turno_em->toDateString(),
                        'pleito_date' => $pleitoDate,
                        'round' => 2,
                        'has_second_round' => true,
                    ];
                }

                return $rounds;
            });
    }
}
