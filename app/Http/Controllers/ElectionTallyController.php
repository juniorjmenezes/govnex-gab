<?php

namespace App\Http\Controllers;

use App\Enums\ElectionType;
use App\Models\CandidatoFavorito;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;
use App\Models\PartidoCor;
use App\Models\User;
use App\Services\Politics\ElectionLifecycleService;
use App\Services\Politics\ElectionResultRecorder;
use App\Services\Politics\MunicipalVoteTally;
use App\Services\Politics\SecondRoundClassifier;
use App\Services\Politics\Tse\TseResultsClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-type TallyRound array{election_id: int, label: string, date: string, pleito_date: string, round: int, has_second_round: bool, type: string}
 */
class ElectionTallyController extends Controller
{
    /**
     * Horário oficial de votação no Brasil: 08h às 17h, sem intervalo.
     */
    private const VOTING_START_HOUR = 8;

    private const VOTING_END_HOUR = 17;

    /**
     * Cargos mostrados na apuração de uma eleição geral — o que o eleitorado
     * do gabinete vota é a chapa nacional e a estadual da sua própria UF.
     * Eleição municipal usa {@see self::MUNICIPAL_CARGOS}.
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

    /**
     * Cargos da apuração de uma eleição municipal — só existem no município
     * do gabinete e vêm da votação nominal sincronizada, não do feed ao vivo
     * do TSE (ver MunicipalVoteTally).
     *
     * @var array<string, string>
     */
    private const MUNICIPAL_CARGOS = [
        'prefeito' => 'Prefeito',
        'vereador' => 'Vereador',
    ];

    /** Cargos proporcionais têm dezenas de candidatos por UF — mostra só os
     * mais votados, com a contagem do restante. */
    private const CANDIDATE_LIMIT = 5;

    /** Cargos que elegem cadeiras (e não um único vencedor). */
    private const SEAT_CARGOS = ['senador', 'deputado_federal', 'deputado_estadual'];

    /** Únicos cargos gerais com 2º turno possível — mesma regra de
     * {@see ElectionResultRecorder::MAJORITY_OFFICES}.
     * Senador e os proporcionais se decidem inteiramente no 1º turno; não
     * fazem parte da apuração do 2º turno. */
    private const SECOND_ROUND_CARGOS = ['presidente', 'governador'];

    /** Total da casa para os cargos de bancada nacional. A Assembleia
     * estadual não entra aqui: o gráfico dela mostra só as cadeiras do estado. */
    private const CHAMBER_TOTALS = ['senador' => 81, 'deputado_federal' => 513];

    /** Cargos de bancada nacional: o gráfico de composição mostra o Brasil
     * inteiro, com as cadeiras do estado do gabinete destacadas — diferente
     * da Assembleia estadual, que só existe dentro de um estado. */
    private const NATIONWIDE_SEAT_CARGOS = ['senador', 'deputado_federal'];

    /** As 27 UFs — usado só para somar a bancada nacional por partido; uma
     * eleição não tem como ter mais nem menos que isso. */
    private const BRAZIL_UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
        'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

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
        'PREFEITO' => 'prefeito',
        'VEREADOR' => 'vereador',
    ];

    public function __construct(
        private readonly TseResultsClient $tseResults,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly MunicipalVoteTally $municipalTally,
        private readonly SecondRoundClassifier $secondRound,
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
                'type' => $selected['type'],
            ];

            $election = Eleicao::query()->find($selected['election_id']);

            // Eleição municipal: prefeito e vereador, só do município do
            // gabinete e da votação nominal já sincronizada — o feed ao vivo
            // do TSE não publica esses cargos, e os cargos gerais não existem
            // nesse pleito. "Meu município" é a própria disputa; não há
            // seção geral.
            if ($election instanceof Eleicao && $election->tipo === ElectionType::Municipal) {
                $raw = $this->fetchMunicipalResults($office, $election, $selected['round']);
                $municipal = $raw === null ? null : [
                    'name' => $office->municipio,
                    'section_percent' => null,
                    'results' => $this->municipalResults($raw),
                ];
                $favorites = $this->favorites($raw ?? [], $office, $election->id);

                return $this->render($office, $rounds, $round, null, $municipal, $favorites);
            }

            // O TSE publica a lista de candidatos (com votos zerados) desde
            // antes da votação fechar, não só depois das 17h — então a
            // busca não tem gate de horário; cada cargo fica `available:
            // false` sozinho se o arquivo realmente não existir ainda (ver
            // TseResultsClient::results()).
            $raw = $this->fetchResults($selected['pleito_date'], $selected['round'], $office->estado);

            // Com o turno já em mãos, aproveita para apurar as disputas da UF
            // do gabinete — resultado de todos os cargos e, no 1º turno, quem
            // foi ao 2º — sem outra leitura do TSE. O comando agendado
            // politics:tally-elections cobre quem não abrir esta tela.
            if ($election instanceof Eleicao) {
                $this->lifecycle->recordLiveTally($election, $office->estado, $selected['round'], $raw);
            }

            $results = $this->results($raw, $selected['has_second_round'], $selected['round']);
            $favorites = $this->favorites($raw, $office, $selected['election_id']);
            $municipal = $this->municipal($office, $selected, $results);
            $results = $this->withNationwideSeats($results, $selected['pleito_date'], $selected['round'], $office->estado);
        }

        return $this->render($office, $rounds, $round, $results, $municipal, $favorites);
    }

    /**
     * @param  Collection<int, TallyRound>  $rounds
     * @param  array<string, mixed>|null  $round
     * @param  array<int, array<string, mixed>>|null  $results
     * @param  array<string, mixed>|null  $municipal
     * @param  array<int, array<string, mixed>>|null  $favorites
     */
    private function render(Gabinete $office, Collection $rounds, ?array $round, ?array $results, ?array $municipal, ?array $favorites): Response
    {
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
        return collect($this->cargosForRound($round))
            ->keys()
            ->mapWithKeys(fn (string $cargo): array => [$cargo => $this->tseResults->results($cargo, $uf, $pleitoDate, $round, $municipality)])
            ->all();
    }

    /**
     * Cargos gerais que o turno pedido realmente apura: no 2º turno, só
     * presidente e governador ({@see self::SECOND_ROUND_CARGOS}) — senador e
     * os proporcionais já se decidiram no 1º e não devem aparecer de novo.
     *
     * @return array<string, string>
     */
    private function cargosForRound(int $round): array
    {
        return $round === 2
            ? array_intersect_key(self::CARGOS, array_flip(self::SECOND_ROUND_CARGOS))
            : self::CARGOS;
    }

    /**
     * Prefeito e vereador do município do gabinete, no formato cru do
     * TseResultsClient. Sem município vinculado, devolve null e a tela pede
     * a vinculação.
     *
     * Antes de ler, classifica o 2º turno de prefeito com a votação já no
     * banco — sem custo quando já classificado, e sem esperar o comando
     * agendado (o mesmo que o PoliticalPanelController faz).
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    private function fetchMunicipalResults(Gabinete $office, Eleicao $election, int $round): ?array
    {
        $municipalityId = $office->municipio_eleitoral_id;

        if ($municipalityId === null) {
            return null;
        }

        if ($election->primeiro_turno_em->lte(today())) {
            $this->secondRound->classify($election, 'prefeito', null, $municipalityId);
        }

        return $this->municipalTally->results($election, $municipalityId, $round);
    }

    /**
     * Mesmo formato de {@see self::results()} para prefeito e vereador.
     * "Eleito", "Vai ao 2º turno" e "concluído" já vêm prontos da fonte
     * (votação nominal + classificação gravada), sem refazer a regra aqui.
     * Vereador é cargo de cadeiras: entra na composição da Câmara
     * Municipal, sem total constitucional (a casa são as cadeiras eleitas).
     *
     * @param  array<string, array<string, mixed>|null>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function municipalResults(array $raw): array
    {
        return collect($raw)
            ->map(function (?array $result, string $cargo): array {
                $candidates = $result['candidates'] ?? [];

                return [
                    'cargo' => $cargo,
                    'label' => self::MUNICIPAL_CARGOS[$cargo],
                    'available' => $result !== null,
                    'section_percent' => null,
                    'total_sections' => null,
                    'candidates' => array_slice($candidates, 0, self::CANDIDATE_LIMIT),
                    'all_candidates' => $candidates,
                    'total_candidates' => count($candidates),
                    'seats' => $cargo === 'vereador'
                        ? $this->seatsByParty(array_values(array_filter($candidates, fn (array $c): bool => $c['elected'])))
                        : null,
                    'goes_to_second_round' => $result['goes_to_second_round'] ?? false,
                    'finished' => $result['finished'] ?? false,
                    'chamber_total' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Apuração geral recortada pela cidade do gabinete, nos mesmos cargos e
     * formato de {@see self::results()} — é o que a tela mostra ao trocar o
     * escopo para a cidade (em vez de uma seção paralela sempre visível).
     * Sem município vinculado, devolve null e a tela não oferece a troca.
     *
     * Quem está "eleito" aqui vem de `$nationalResults`, não dos votos da
     * própria cidade: presidente se decide na contagem nacional e governador
     * na estadual — o candidato mais votado no município pode não ser o
     * eleito de verdade.
     *
     * @param  array<string, mixed>  $selected
     * @param  array<int, array<string, mixed>>  $nationalResults
     * @return array{name: string, section_percent: float|null, results: array<int, array<string, mixed>>}|null
     */
    private function municipal(Gabinete $office, array $selected, array $nationalResults): ?array
    {
        $code = $office->municipioEleitoral?->codigo_tse;

        if ($code === null || $code === '') {
            return null;
        }

        $electedByCargo = collect($nationalResults)->mapWithKeys(
            fn (array $result): array => [
                $result['cargo'] => collect($result['all_candidates'])
                    ->where('elected', true)
                    ->pluck('number')
                    ->all(),
            ],
        )->all();

        $raw = $this->fetchResults($selected['pleito_date'], $selected['round'], $office->estado, $code);
        $results = $this->results($raw, false, $selected['round'], $electedByCargo);
        $sectionPercent = collect($results)->first(fn (array $r): bool => $r['section_percent'] !== null)['section_percent'] ?? null;

        return [
            'name' => $office->municipio,
            'section_percent' => $sectionPercent,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $raw
     * @param  array<string, array<int, string>>|null  $electedOverride  Número dos
     *                                                                   candidatos realmente eleitos por cargo, já apurado na votação que
     *                                                                   decide o cargo (nacional para presidente, estadual para
     *                                                                   governador). Usado pelo recorte municipal: lá os votos são só da
     *                                                                   cidade, e quem ganha na cidade não necessariamente é quem a
     *                                                                   eleição elege — isso só se decide na contagem de verdade.
     * @return array<int, array<string, mixed>>
     */
    private function results(array $raw, bool $electionHasSecondRound, int $round, ?array $electedOverride = null): array
    {
        return collect($this->cargosForRound($round))
            ->map(function (string $label, string $cargo) use ($raw, $electionHasSecondRound, $round, $electedOverride): array {
                $result = $raw[$cargo] ?? null;
                $candidates = $result['candidates'] ?? [];
                $sectionPercent = $result['section_percent'] ?? null;
                $finished = $sectionPercent !== null && $sectionPercent >= 100;

                if ($electedOverride !== null) {
                    $electedNumbers = $electedOverride[$cargo] ?? [];
                    $candidates = array_map(function (array $c) use ($electedNumbers): array {
                        $c['elected'] = in_array($c['number'], $electedNumbers, true);

                        return $c;
                    }, $candidates);
                } elseif ($round === 1 && ! in_array($cargo, self::SEAT_CARGOS, true)) {
                    // No 1º turno de um cargo majoritário (presidente/governador),
                    // o TSE marca `e:"s"` nos dois candidatos classificados para o
                    // 2º turno — não só em quem venceu de fato. Sem maioria
                    // absoluta (>50%), ninguém está eleito ainda; são só os dois
                    // que avançam. Cargos de cadeira (SEAT_CARGOS) não entram
                    // aqui: lá "eleito" já significa vaga conquistada mesmo.
                    $candidates = array_map(function (array $c): array {
                        $c['elected'] = $c['elected'] && $c['vote_percent'] > 50;

                        return $c;
                    }, $candidates);
                }

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
     * Troca `seats` dos cargos de bancada nacional ({@see self::NATIONWIDE_SEAT_CARGOS})
     * pela soma por partido no Brasil inteiro, com a contagem do estado do
     * gabinete junto (`home_seats`) para o gráfico destacar essas cadeiras
     * sem perder o resto do país.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function withNationwideSeats(array $results, string $pleitoDate, int $round, string $homeUf): array
    {
        return array_map(function (array $result) use ($pleitoDate, $round, $homeUf): array {
            if (in_array($result['cargo'], self::NATIONWIDE_SEAT_CARGOS, true)) {
                $result['seats'] = $this->nationwideSeatsByParty($result['cargo'], $pleitoDate, $round, $homeUf);
            }

            return $result;
        }, $results);
    }

    /**
     * Soma as cadeiras eleitas de um cargo nas 27 UFs, por partido — uma
     * chamada ao TSE por estado. Cacheado à parte (5 min, bem mais que o
     * cache de 90s do resultado por UF em si): a composição da casa praticamente
     * não muda depois que a apuração de cada estado fecha, e refazer 27
     * requisições a cada 90s de polling do front seria desperdício.
     *
     * @return array<int, array{party: string, seats: int, home_seats: int, color: string|null}>
     */
    private function nationwideSeatsByParty(string $cargo, string $pleitoDate, int $round, string $homeUf): array
    {
        $cacheKey = "tse.nationwide-seats.{$cargo}.{$pleitoDate}.{$round}.{$homeUf}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($cargo, $pleitoDate, $round, $homeUf): array {
            $colors = PartidoCor::colorMap();
            $byParty = [];

            foreach (self::BRAZIL_UFS as $uf) {
                $result = $this->tseResults->results($cargo, $uf, $pleitoDate, $round);

                foreach ($result['candidates'] ?? [] as $candidate) {
                    if (! $candidate['elected']) {
                        continue;
                    }

                    $party = $candidate['party'];
                    $byParty[$party] ??= ['seats' => 0, 'home_seats' => 0];
                    $byParty[$party]['seats']++;

                    if ($uf === $homeUf) {
                        $byParty[$party]['home_seats']++;
                    }
                }
            }

            return collect($byParty)
                ->map(fn (array $data, string $party): array => [
                    'party' => $party,
                    'seats' => $data['seats'],
                    'home_seats' => $data['home_seats'],
                    'color' => $colors[PartidoCor::normalizeSigla($party)] ?? null,
                ])
                ->sortByDesc('seats')
                ->values()
                ->all();
        });
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

                // Só cargos que este turno apura: vereador não tem 2º turno,
                // e cargo municipal não existe numa eleição geral.
                if ($cargo === null || ! array_key_exists($cargo, $raw)) {
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
                    'cargo_label' => (self::CARGOS + self::MUNICIPAL_CARGOS)[$cargo],
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
     * @return Collection<int, TallyRound>
     */
    private function rounds(): Collection
    {
        return Eleicao::query()
            ->orderByDesc('ano')
            ->get()
            ->toBase()
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
                        'type' => $election->tipo->value,
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
                        'type' => $election->tipo->value,
                    ];
                }

                return $rounds;
            });
    }
}
