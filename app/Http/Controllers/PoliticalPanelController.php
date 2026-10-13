<?php

namespace App\Http\Controllers;

use App\Enums\CandidateScope;
use App\Enums\ElectionType;
use App\Models\CandidatoFavorito;
use App\Models\CandidatoPolitico;
use App\Models\Cidadao;
use App\Models\ComparecimentoEleitoralMunicipio;
use App\Models\Eleicao;
use App\Models\EleitoradoMunicipioSnapshot;
use App\Models\Gabinete;
use App\Models\MediaPesquisaEleitoral;
use App\Models\NoticiaCandidato;
use App\Models\PartidoCor;
use App\Models\PesquisaEleitoral;
use App\Models\ResultadoCandidatoEleicao;
use App\Models\SincronizacaoTse;
use App\Models\User;
use App\Models\VotacaoCandidatoMunicipio;
use App\Services\Politics\Polls\MediaCalculator;
use App\Services\Politics\SecondRoundClassifier;
use App\Support\PerPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PoliticalPanelController extends Controller
{
    public function __construct(
        private readonly MediaCalculator $mediaCalculator,
        private readonly SecondRoundClassifier $secondRound,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);
        $office = Gabinete::query()
            ->with(['municipioEleitoral', 'candidatoTitular'])
            ->findOrFail($user->gabinete_id);
        $elections = Eleicao::query()
            ->orderByDesc('ano')
            ->get();
        $selectedElection = $this->selectedElection($request, $elections->all());
        $filters = [
            'eleicao_id' => $selectedElection?->id,
            'q' => trim($request->string('q')->toString()),
            'cargo' => $request->string('cargo')->toString(),
            'partido' => $request->string('partido')->toString(),
            'favoritos' => $request->boolean('favoritos'),
            'mostrar_primeiro_turno' => $request->boolean('mostrar_primeiro_turno'),
        ];

        // Prefeito se classifica aqui mesmo, sem esperar o comando agendado:
        // a fonte é a votação nominal já sincronizada no banco, então custa
        // uma consulta e não sai para a rede (presidente e governador
        // dependem do TSE ao vivo — ver ElectionLifecycleService::tally()).
        if ($selectedElection instanceof Eleicao
            && $selectedElection->tipo === ElectionType::Municipal
            && $office->municipio_eleitoral_id !== null
            && $selectedElection->primeiro_turno_em->lte(today())) {
            $this->secondRound->classify($selectedElection, 'prefeito', null, $office->municipio_eleitoral_id);
        }

        $candidateBase = CandidatoPolitico::query();
        $this->visibleCandidates(
            $candidateBase,
            $office,
            $selectedElection,
            showFullFirstRound: $filters['mostrar_primeiro_turno'],
        );

        // Independente do toggle estar ligado: só vale oferecê-lo quando
        // existe alguma disputa já decidida para o 2º turno (clássica ou
        // decidida já no 1º) dentro do território do gabinete — sem isso,
        // ligar o toggle devolveria exatamente a mesma lista.
        $runoffQuery = CandidatoPolitico::query();
        $this->visibleCandidates($runoffQuery, $office, $selectedElection, showFullFirstRound: true);
        $hasSecondRoundDecided = $runoffQuery
            ->whereNotNull('avancou_segundo_turno')
            ->exists();

        $candidates = (clone $candidateBase)
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['q'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('nome_urna', 'like', "%{$search}%")
                        ->orWhere('nome', 'like', "%{$search}%")
                        ->orWhere('numero', 'like', "%{$search}%")
                        ->orWhere('partido_sigla', 'like', "%{$search}%");
                });
            })
            ->when($filters['cargo'] !== '', fn (Builder $query) => $query
                ->where('cargo', $filters['cargo']))
            ->when($filters['partido'] !== '', fn (Builder $query) => $query
                ->where('partido_sigla', $filters['partido']))
            ->when($filters['favoritos'], fn (Builder $query) => $query
                ->whereHas('favoritos', fn (Builder $favorites) => $favorites
                    ->where('gabinete_id', $office->id)))
            ->withExists([
                'favoritos as is_favorite' => fn (Builder $query) => $query
                    ->where('gabinete_id', $office->id),
            ])
            // Alimenta o link de notícias do favorito, sem carregar as
            // notícias em si: elas são buscadas quando o modal abre.
            ->withCount([
                'noticias as news_count' => fn (Builder $query) => $query
                    ->where('gabinete_id', $office->id),
            ])
            ->orderByDesc('is_favorite')
            ->orderBy('cargo')
            ->orderBy('nome_urna')
            ->paginate(PerPage::resolve($request, 18))
            ->withQueryString();
        $partyColors = PartidoCor::colorMap();
        $electionResults = $this->electionResults($candidates->getCollection());
        $candidates->getCollection()->each(function (CandidatoPolitico $candidate) use ($partyColors, $office, $electionResults): void {
            $candidate->party_color = $candidate->partido_sigla !== null
                ? ($partyColors[PartidoCor::normalizeSigla($candidate->partido_sigla)] ?? null)
                : null;
            $candidate->is_holder = $candidate->id === $office->candidato_titular_id;
            $candidate->resultado = $electionResults[$candidate->id] ?? null;
        });

        $latestElectorate = $office->municipio_eleitoral_id
            ? EleitoradoMunicipioSnapshot::query()
                ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
                ->latest('data_referencia')
                ->first()
            : null;
        $lastTurnout = $office->municipio_eleitoral_id
            ? ComparecimentoEleitoralMunicipio::query()
                ->with('eleicao:id,nome')
                ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
                ->orderByDesc('data_eleicao')
                ->orderByDesc('turno')
                ->first()
            : null;
        $holderVote = $office->candidato_titular_id && $office->municipio_eleitoral_id
            ? VotacaoCandidatoMunicipio::query()
                ->with('eleicao:id,nome')
                ->where('candidato_politico_id', $office->candidato_titular_id)
                ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
                ->orderByDesc('data_eleicao')
                ->orderByDesc('turno')
                ->first()
            : null;
        $internalVoters = Cidadao::query()->where('eleitor', true)->count();
        $officialEligible = $latestElectorate?->eleitores_aptos;
        $nextRound = $this->nextRound();

        // Eleição municipal já realizada muda o que o painel oferece: entra a
        // apuração e sai a pesquisa de intenção de voto, que perdeu a função
        // com o resultado publicado.
        $concludedMunicipal = $selectedElection instanceof Eleicao
            && $selectedElection->tipo === ElectionType::Municipal
            && $selectedElection->primeiro_turno_em->lte(today());
        // A exceção é o prefeito ir a 2º turno: até a votação dele, a
        // pesquisa (agora de 2º turno) volta a ter função.
        $pendingMayoralRunoff = $concludedMunicipal
            && ($selectedElection->segundo_turno_em === null || $selectedElection->segundo_turno_em->gte(today()))
            && $this->secondRound->activeRound($selectedElection, 'prefeito', null, $office->municipio_eleitoral_id) === 2;

        return Inertia::render('politics/index', [
            'elections' => $elections->map(fn (Eleicao $election): array => [
                'id' => $election->id,
                'name' => $election->nome,
                'year' => $election->ano,
                'type' => $election->tipo->value,
                'type_label' => $election->tipo->label(),
                'first_round_at' => $election->primeiro_turno_em->toDateString(),
                'second_round_at' => $election->segundo_turno_em?->toDateString(),
                'source_updated_at' => $election->fonte_atualizada_em?->toIso8601String(),
            ]),
            'selectedElectionId' => $selectedElection?->id,
            'candidates' => $candidates,
            'filters' => $filters,
            'hasSecondRoundDecided' => $hasSecondRoundDecided,
            'options' => [
                'offices' => (clone $candidateBase)
                    ->whereNotNull('cargo')
                    ->distinct()
                    ->orderBy('cargo')
                    ->pluck('cargo'),
                'parties' => (clone $candidateBase)
                    ->whereNotNull('partido_sigla')
                    ->distinct()
                    ->orderBy('partido_sigla')
                    ->pluck('partido_sigla'),
            ],
            'stats' => [
                'official_eligible' => $officialEligible,
                'official_reference' => $latestElectorate?->data_referencia?->toDateString(),
                'last_turnout' => $lastTurnout?->comparecimento,
                'last_turnout_eligible' => $lastTurnout?->eleitores_aptos,
                'last_turnout_percentage' => $lastTurnout && $lastTurnout->eleitores_aptos > 0
                    ? round(($lastTurnout->comparecimento / $lastTurnout->eleitores_aptos) * 100, 2)
                    : null,
                'last_turnout_abstentions' => $lastTurnout?->abstencoes,
                'last_election_name' => $lastTurnout?->eleicao->nome
                    ?? ($lastTurnout ? "Eleições {$lastTurnout->ano}" : null),
                'last_election_date' => $lastTurnout?->data_eleicao->toDateString(),
                'last_election_round' => $lastTurnout?->turno,
                'holder_configured_number' => $office->numero_eleitoral,
                'holder_candidate_name' => $office->candidatoTitular?->nome_urna,
                'holder_candidate_party' => $office->candidatoTitular?->partido_sigla,
                'holder_votes' => $holderVote?->votos_nominais,
                'holder_result_status' => $holderVote?->situacao_totalizacao,
                'holder_elected' => $holderVote?->eleito,
                'holder_election_name' => $holderVote?->eleicao->nome,
                'internal_voters' => $internalVoters,
                'coverage_percentage' => $officialEligible !== null && $officialEligible > 0
                    ? round(($internalVoters / $officialEligible) * 100, 2)
                    : null,
                'favorites' => $selectedElection
                    ? CandidatoFavorito::query()
                        ->whereHas('candidato', fn (Builder $query) => $query
                            ->where('eleicao_id', $selectedElection->id))
                        ->count()
                    : 0,
            ],
            'municipalElection' => $concludedMunicipal
                ? $this->municipalElectionResult($office, $selectedElection)
                : null,
            'municipality' => [
                'name' => $office->municipio,
                'state' => $office->estado,
                'mapped' => $office->municipio_eleitoral_id !== null,
                'tse_code' => $office->municipioEleitoral?->codigo_tse,
            ],
            'countdown' => $nextRound ? [
                'label' => $nextRound['election']->nome,
                'round_label' => $nextRound['round'] === 2 ? '2º turno' : '1º turno',
                'target' => CarbonImmutable::parse(
                    $nextRound['date'],
                    $office->timezone ?? 'America/Sao_Paulo',
                )->startOfDay()->toIso8601String(),
                'date' => $nextRound['date'],
            ] : null,
            'serverNow' => now()->toIso8601String(),
            'canFavorite' => $user->gabinete_id !== null,
            'polls' => $concludedMunicipal && ! $pendingMayoralRunoff
                ? null
                : $this->polls($office, $selectedElection),
            'sync' => [
                // Datasets globais do TSE — sempre gravados com gabinete_id
                // nulo (ver docblock de latestSync()).
                'electorate' => $this->latestSync('electorate', null),
                'turnout' => $this->latestSync('turnout', null),
                'candidate_votes' => $this->latestSync('candidate_votes', null),
                'candidates' => $this->latestSync('candidates', null),
                // O PollingData só cobre Presidente (dataset pollingdata_polls,
                // sempre no ano da eleição geral) — eleições municipais nunca
                // têm pesquisas sincronizadas automaticamente, então não faz
                // sentido mostrar o status de uma sincronização que não se
                // aplica a essa corrida.
                'polls' => $selectedElection?->tipo === ElectionType::Municipal
                    ? null
                    : $this->latestSync('pollingdata_polls', $office->id),
            ],
        ]);
    }

    /**
     * Resultado da eleição de cada candidatura da página, quando já gravado —
     * a coluna "Situação" o mostra no lugar do status de deferimento. Uma
     * fonte por granularidade, sem sobreposição de cargos: presidente,
     * governador, senador e deputados em `resultados_candidatos_eleicao`
     * (apuração do TSE, ver ElectionResultRecorder), prefeito e vereador em
     * `votacoes_candidatos_municipio`. Vale o turno mais recente.
     *
     * @param  Collection<int, CandidatoPolitico>  $candidates
     * @return array<int, array{situacao: string, turno: int, votos: int, percentual: float|null, eleito: bool}>
     */
    private function electionResults(Collection $candidates): array
    {
        $ids = $candidates->modelKeys();

        if ($ids === []) {
            return [];
        }

        // Em ordem de turno: o mais recente sobrescreve o anterior.
        $results = [];

        $majority = ResultadoCandidatoEleicao::query()
            ->whereIn('candidato_politico_id', $ids)
            ->orderBy('turno')
            ->get();

        foreach ($majority as $result) {
            $results[$result->candidato_politico_id] = [
                'situacao' => $result->situacao_totalizacao,
                'turno' => $result->turno,
                'votos' => $result->votos,
                'percentual' => (float) $result->percentual_validos,
                'eleito' => $result->eleito,
            ];
        }

        $municipal = VotacaoCandidatoMunicipio::query()
            ->whereIn('candidato_politico_id', $ids)
            ->whereNotNull('situacao_totalizacao')
            ->orderBy('turno')
            ->get();

        foreach ($municipal as $vote) {
            $results[$vote->candidato_politico_id] = [
                'situacao' => (string) $vote->situacao_totalizacao,
                'turno' => $vote->turno,
                'votos' => $vote->votos_nominais,
                // A votação municipal não traz o total de válidos da disputa.
                'percentual' => null,
                'eleito' => $vote->eleito,
            ];
        }

        return $results;
    }

    /**
     * Apuração da eleição municipal escolhida no seletor. É a única visão
     * que compara o titular com quem mais disputou — a lista de candidatos
     * mostra nomes, não resultado.
     *
     * Devolve null quando não há o que resumir: gabinete sem município
     * eleitoral vinculado, ou votação nominal daquele município ainda não
     * sincronizada.
     *
     * @return array{
     *     election: array{name: string, year: int, date: string},
     *     turnout: array{eligible: int, voted: int, abstentions: int, percentage: float|null}|null,
     *     candidates: int,
     *     seats: int,
     *     holder: array{name: string, party: string|null, number: string|null, votes: int, position: int, elected: bool, result_status: string|null}|null,
     *     elected: list<array{position: int, name: string, party: string|null, party_color: string|null, number: string|null, votes: int, is_holder: bool}>,
     *     parties: list<array{party: string, seats: int, votes: int, color: string|null}>,
     * }|null
     */
    private function municipalElectionResult(Gabinete $office, Eleicao $election): ?array
    {
        if ($office->municipio_eleitoral_id === null) {
            return null;
        }

        $votes = VotacaoCandidatoMunicipio::query()
            ->with('candidato:id,nome,nome_urna,numero,partido_sigla,cargo')
            ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
            ->where('eleicao_id', $election->id)
            ->orderByDesc('votos_nominais')
            ->get();

        if ($votes->isEmpty()) {
            return null;
        }

        // São duas disputas com leituras diferentes, e juntá-las num ranking
        // só seria enganoso: prefeito é majoritária (vence quem tem mais voto,
        // e quem decide é o último turno), vereador é proporcional (a cadeira
        // não sai só do número de votos de cada candidato).
        $mayorVotes = $votes
            ->filter(fn (VotacaoCandidatoMunicipio $vote): bool => $this->isMayoralRace($vote))
            ->values();
        $decisiveRound = $mayorVotes->max('turno') ?? 1;
        $mayorVotes = $mayorVotes->where('turno', $decisiveRound)->values();
        $councilVotes = $votes
            ->reject(fn (VotacaoCandidatoMunicipio $vote): bool => $this->isMayoralRace($vote))
            ->where('turno', 1)
            ->values();

        $positions = [];
        $position = 0;

        foreach ($councilVotes as $vote) {
            $position++;
            $positions[$vote->candidato_politico_id] = $position;
        }

        $elected = $councilVotes->where('eleito', true)->values();
        // Os derrotados mais votados: é onde se lê a concorrência real de quem
        // ficou perto da cadeira. Limitado porque numa capital são milhares.
        $runnersUp = $councilVotes->where('eleito', false)->take(10)->values();
        $holderVote = $office->candidato_titular_id !== null
            ? $councilVotes->firstWhere('candidato_politico_id', $office->candidato_titular_id)
            : null;
        $turnout = ComparecimentoEleitoralMunicipio::query()
            ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
            ->where('eleicao_id', $election->id)
            ->orderBy('turno')
            ->first();

        // Mesma cor que o partido tem no resto do painel (ver PartidoCor e
        // /admin/cores-partidos); sem cor cadastrada fica o badge neutro.
        $partyColors = PartidoCor::colorMap();
        $colorFor = fn (?string $sigla): ?string => $sigla !== null
            ? ($partyColors[PartidoCor::normalizeSigla($sigla)] ?? null)
            : null;
        $parties = [];

        foreach ($elected as $vote) {
            $sigla = $vote->candidato->partido_sigla;
            $party = $sigla ?? 'Sem partido';
            $parties[$party] ??= [
                'party' => $party,
                'seats' => 0,
                'votes' => 0,
                'color' => $colorFor($sigla),
            ];
            $parties[$party]['seats']++;
            $parties[$party]['votes'] += $vote->votos_nominais;
        }

        $parties = array_values($parties);
        usort(
            $parties,
            fn (array $a, array $b): int => [$b['seats'], $b['votes']] <=> [$a['seats'], $a['votes']],
        );

        return [
            'election' => [
                'name' => $election->nome,
                'year' => $election->ano,
                'date' => $election->primeiro_turno_em->toDateString(),
            ],
            'turnout' => $turnout instanceof ComparecimentoEleitoralMunicipio ? [
                'eligible' => $turnout->eleitores_aptos,
                'voted' => $turnout->comparecimento,
                'abstentions' => $turnout->abstencoes,
                'percentage' => $turnout->eleitores_aptos > 0
                    ? round($turnout->comparecimento / $turnout->eleitores_aptos * 100, 2)
                    : null,
            ] : null,
            'candidates' => $councilVotes->count(),
            'seats' => $elected->count(),
            'mayor' => $mayorVotes->isEmpty() ? null : [
                'round' => $decisiveRound,
                'candidates' => array_values($mayorVotes
                    ->map(fn (VotacaoCandidatoMunicipio $vote, int $index): array => [
                        'position' => $index + 1,
                        'name' => $vote->candidato->nome_urna,
                        'party' => $vote->candidato->partido_sigla,
                        'party_color' => $colorFor($vote->candidato->partido_sigla),
                        'number' => $vote->candidato->numero,
                        'votes' => $vote->votos_nominais,
                        'elected' => (bool) $vote->eleito,
                    ])
                    ->all()),
            ],
            'holder' => $holderVote instanceof VotacaoCandidatoMunicipio ? [
                'name' => $holderVote->candidato->nome_urna,
                'party' => $holderVote->candidato->partido_sigla,
                'number' => $holderVote->candidato->numero,
                'votes' => $holderVote->votos_nominais,
                'position' => $positions[$holderVote->candidato_politico_id],
                'elected' => (bool) $holderVote->eleito,
                'result_status' => $holderVote->situacao_totalizacao,
            ] : null,
            'elected' => array_values($elected
                ->map(fn (VotacaoCandidatoMunicipio $vote): array => [
                    'position' => $positions[$vote->candidato_politico_id],
                    'name' => $vote->candidato->nome_urna,
                    'party' => $vote->candidato->partido_sigla,
                    'party_color' => $colorFor($vote->candidato->partido_sigla),
                    'number' => $vote->candidato->numero,
                    'votes' => $vote->votos_nominais,
                    'is_holder' => $vote->candidato_politico_id === $office->candidato_titular_id,
                ])
                ->all()),
            'runners_up' => array_values($runnersUp
                ->map(fn (VotacaoCandidatoMunicipio $vote): array => [
                    'position' => $positions[$vote->candidato_politico_id],
                    'name' => $vote->candidato->nome_urna,
                    'party' => $vote->candidato->partido_sigla,
                    'party_color' => $colorFor($vote->candidato->partido_sigla),
                    'number' => $vote->candidato->numero,
                    'votes' => $vote->votos_nominais,
                    'is_holder' => $vote->candidato_politico_id === $office->candidato_titular_id,
                ])
                ->all()),
            'parties' => $parties,
        ];
    }

    /** Prefeito e vereador dividem a mesma tabela de votação nominal. */
    private function isMayoralRace(VotacaoCandidatoMunicipio $vote): bool
    {
        return Str::ascii(mb_strtoupper($vote->candidato->cargo)) === 'PREFEITO';
    }

    public function favorite(Request $request, CandidatoPolitico $candidate): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
            && $user->gabinete_id !== null,
            403,
        );
        $office = Gabinete::query()->findOrFail($user->gabinete_id);
        $this->ensureCandidateIsVisible($candidate, $office);

        CandidatoFavorito::query()->firstOrCreate(
            ['candidato_politico_id' => $candidate->id],
            ['escolhido_por_id' => $user->id],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Candidato adicionado aos favoritos.',
        ]);

        return back();
    }

    public function unfavorite(Request $request, CandidatoPolitico $candidate): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
            && $user->gabinete_id !== null,
            403,
        );
        $office = Gabinete::query()->findOrFail($user->gabinete_id);
        $this->ensureCandidateIsVisible($candidate, $office);

        // O titular é favorito por definição do vínculo do gabinete, não por
        // escolha — desmarcá-lo desligaria a coleta de notícias e o destaque
        // dele nas pesquisas do próprio gabinete.
        if ($office->candidato_titular_id === $candidate->id) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'O titular do gabinete fica sempre nos favoritos.',
            ]);

            return back();
        }

        CandidatoFavorito::query()
            ->where('candidato_politico_id', $candidate->id)
            ->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Candidato removido dos favoritos.',
        ]);

        return back();
    }

    /**
     * Notícias de um candidato, para o modal do painel. Paginadas de 6 em 6;
     * o escopo de tenant do NoticiaCandidato garante que um gabinete não
     * enxergue o casamento feito para outro.
     */
    public function news(Request $request, CandidatoPolitico $candidate): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);
        $office = Gabinete::query()->findOrFail($user->gabinete_id);
        $this->ensureCandidateIsVisible($candidate, $office);

        $news = NoticiaCandidato::query()
            ->where('candidato_politico_id', $candidate->id)
            ->whereHas('noticia')
            ->with([
                'noticia:id,fonte_rss_id,titulo,resumo,url,imagem_url,publicado_em',
                'noticia.fonte:id,nome',
            ])
            ->join('noticias_rss', 'noticias_rss.id', '=', 'noticias_candidatos.noticia_rss_id')
            ->orderByDesc('noticias_rss.publicado_em')
            ->orderByDesc('noticias_candidatos.id')
            ->select('noticias_candidatos.*')
            ->paginate(6, page: $request->integer('page') ?: 1);

        return response()->json([
            'data' => collect($news->items())
                ->map(fn (NoticiaCandidato $match): array => [
                    'id' => $match->id,
                    'title' => $match->noticia->titulo,
                    'summary' => $match->noticia->resumo,
                    'url' => $match->noticia->url,
                    'image_url' => $match->noticia->imagem_url,
                    'published_at' => $match->noticia->publicado_em?->toIso8601String(),
                    'source' => $match->noticia->fonte?->nome,
                ])
                ->all(),
            'current_page' => $news->currentPage(),
            'last_page' => $news->lastPage(),
            'total' => $news->total(),
        ]);
    }

    /** @param array<int, Eleicao> $elections */
    private function selectedElection(Request $request, array $elections): ?Eleicao
    {
        $requestedId = $request->integer('eleicao_id');

        if ($requestedId > 0) {
            foreach ($elections as $election) {
                if ($election->id === $requestedId) {
                    return $election;
                }
            }
        }

        foreach (array_reverse($elections) as $election) {
            if ($election->primeiro_turno_em->gte(today())) {
                return $election;
            }
        }

        return $elections[0] ?? null;
    }

    /** @param Builder<CandidatoPolitico> $query */
    /**
     * Próximo turno ainda não realizado entre todas as eleições cadastradas
     * (1º e 2º turnos). Antes só olhava o 1º turno, então depois do dia da
     * votação o card de contagem sumia sem nunca mostrar o 2º turno.
     *
     * @return array{election: Eleicao, round: int, date: string}|null
     */
    private function nextRound(): ?array
    {
        $today = today()->toDateString();

        return Eleicao::query()
            ->get()
            ->flatMap(fn (Eleicao $election): array => array_filter([
                $election->primeiro_turno_em?->toDateString() >= $today
                    ? ['election' => $election, 'round' => 1, 'date' => $election->primeiro_turno_em->toDateString()]
                    : null,
                $election->segundo_turno_em !== null && $election->segundo_turno_em->toDateString() >= $today
                    ? ['election' => $election, 'round' => 2, 'date' => $election->segundo_turno_em->toDateString()]
                    : null,
            ]))
            ->sortBy('date')
            ->first();
    }

    /**
     * Depois que o 1º turno da eleição selecionada já passou
     * (`primeiro_turno_em` no passado), a lista deixa de ser "por disputa" e
     * passa a valer para a eleição inteira: só quem tem
     * `avancou_segundo_turno = true` continua visível — cargos sem 2º turno
     * (senador, deputado, vereador) e quem venceu um cargo majoritário já no
     * 1º turno saem da lista até a próxima eleição, mesmo sem relação com a
     * disputa que efetivamente foi a 2º turno (ver SecondRoundClassifier).
     *
     * `$showFullFirstRound = true` (toggle "Mostrar 1º turno") desliga esse
     * corte por completo e devolve o universo inteiro de antes do 1º turno —
     * eleitos no 1º turno, eliminados, e cargos sem 2º turno incluídos. Não é
     * mais um filtro adicional de "eliminados": é o mesmo recorte territorial
     * de sempre, sem o corte de 2º turno. Antes do 1º turno,
     * `avancou_segundo_turno` é sempre nulo e nada é filtrado aqui de
     * qualquer forma.
     *
     * `$enforceRoundVisibility = false` desliga esse filtro por completo —
     * usado por `ensureCandidateIsVisible()`: um favorito marcado antes do
     * corte (ex.: um deputado, ou o eliminado de uma disputa) precisa
     * continuar podendo ser desfavoritado, ter notícias lidas etc., mesmo
     * depois de sair da lista geral.
     *
     * @param  Builder<CandidatoPolitico>  $query
     */
    private function isPastFirstRound(?Eleicao $election): bool
    {
        return $election instanceof Eleicao
            && $election->primeiro_turno_em->lte(today());
    }

    private function visibleCandidates(
        Builder $query,
        Gabinete $office,
        ?Eleicao $election,
        bool $showFullFirstRound = false,
        bool $enforceRoundVisibility = true,
    ): void {
        $pastFirstRound = $enforceRoundVisibility
            && ! $showFullFirstRound
            && $this->isPastFirstRound($election);

        $query
            ->when($pastFirstRound, fn (Builder $query) => $query
                ->where('avancou_segundo_turno', true))
            ->when(
                $election,
                fn (Builder $query) => $query->where('eleicao_id', $election->id),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->where(function (Builder $query) use ($office): void {
                $query->where('abrangencia', CandidateScope::National)
                    ->orWhere(function (Builder $state) use ($office): void {
                        $state->where('abrangencia', CandidateScope::State)
                            ->where('uf', $office->estado);
                    });

                if ($office->municipio_eleitoral_id !== null) {
                    $query->orWhere(function (Builder $municipal) use ($office): void {
                        $municipal->where('abrangencia', CandidateScope::Municipal)
                            ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id);
                    });
                }
            });
    }

    private function ensureCandidateIsVisible(
        CandidatoPolitico $candidate,
        Gabinete $office,
    ): void {
        $query = CandidatoPolitico::query()->whereKey($candidate->id);
        $this->visibleCandidates(
            $query,
            $office,
            $candidate->eleicao,
            showFullFirstRound: true,
            enforceRoundVisibility: false,
        );
        abort_unless($query->exists(), 404);
    }

    /** @return array{source: string, source_url: string, state: string, municipality: string, election_type: string|null, available: bool, offices: array<int, array<string, mixed>>} */
    private function polls(Gabinete $office, ?Eleicao $election): array
    {
        $empty = [
            'source' => 'PollingData',
            'source_url' => 'https://flex.pollingdata.com.br/',
            'state' => mb_strtoupper($office->estado),
            'municipality' => $office->municipio,
            'election_type' => $election?->tipo->value,
            'available' => false,
            'offices' => [],
        ];

        if (! $election) {
            return $empty;
        }

        $favoriteIds = CandidatoFavorito::query()
            ->where('gabinete_id', $office->id)
            ->whereHas('candidato', fn (Builder $query) => $query
                ->where('eleicao_id', $election->id))
            ->pluck('candidato_politico_id')
            ->map(fn (int $id): int => $id)
            ->all();
        $partyColors = PartidoCor::colorMap();
        $colorForParty = fn (?string $sigla): ?string => $sigla !== null
            ? ($partyColors[PartidoCor::normalizeSigla($sigla)] ?? null)
            : null;
        $offices = [];
        $available = false;

        $officeDefinitions = $election->tipo === ElectionType::Municipal
            ? ['prefeito' => 'Prefeito']
            : ['presidente' => 'Presidente', 'governador' => 'Governador', 'senador' => 'Senado'];

        // Depois do 1º turno, a lista de cargos oferecidos pra pesquisa
        // segue a mesma regra territorial de visibleCandidates(): só fica o
        // cargo cuja disputa, PRO TERRITÓRIO deste gabinete (UF pra
        // governador, município pra prefeito, nacional pra presidente), foi
        // classificada pro 2º turno. Senador nunca tem 2º turno e sai da
        // lista assim que o 1º turno passa, mesmo sem relação com a disputa
        // que de fato foi a 2º turno.
        if ($this->isPastFirstRound($election)) {
            $officeDefinitions = array_filter(
                $officeDefinitions,
                fn (string $label, string $officeType): bool => $this->secondRound->activeRound(
                    $election,
                    $officeType,
                    mb_strtoupper($office->estado),
                    $office->municipio_eleitoral_id,
                ) === 2,
                ARRAY_FILTER_USE_BOTH,
            );
        }

        foreach ($officeDefinitions as $officeType => $label) {
            // Presidente é sincronizado nacionalmente (obrigatório) e,
            // opcionalmente, por UF — quando existir pesquisa estadual pro
            // UF do próprio gabinete, ela é priorizada na exibição; sem
            // isso, cai de volta pra visão nacional (BR).
            $dataState = mb_strtoupper($office->estado);
            $dataCity = $officeType === 'prefeito' ? $office->municipio : null;
            // Disputa já classificada para o 2º turno passa a mostrar só as
            // pesquisas de 2º turno; senador nunca tem 2º turno e fica no 1º.
            $round = $this->secondRound->activeRound(
                $election,
                $officeType,
                $dataState,
                $office->municipio_eleitoral_id,
            );
            $officePolls = $this->officePolls($election, $officeType, $round, $dataState, $dataCity);

            if ($officeType === 'presidente' && $officePolls->isEmpty() && $dataState !== 'BR') {
                $dataState = 'BR';
                $officePolls = $this->officePolls($election, $officeType, $round, $dataState, null);
            }

            $latest = $officePolls->first();
            $latestExternalCandidateIds = $latest?->resultados
                ->pluck('external_candidate_id')
                ->all() ?? [];
            // Preferimos nossa própria média (MediaCalculator), calculada a
            // partir do histórico de pesquisas individuais já persistido —
            // mas só quando ela realmente agrega mais de uma pesquisa: com
            // uma só, ela reproduziria essa única pesquisa disfarçada de
            // "média". Nesses casos (e sempre para presidente, cuja fonte
            // nunca publica detalhamento por candidato) caímos de volta para
            // `/averages` do ElectioLab, que tem histórico mais amplo que o
            // localmente sincronizado.
            $ownAverages = $this->mediaCalculator->calcular($officePolls);

            if ($latestExternalCandidateIds !== [] && $ownAverages !== []) {
                $latestCandidateIds = $latest->resultados
                    ->pluck('candidato_politico_id')
                    ->filter()
                    ->all();

                $ownAverages = array_values(array_filter(
                    $ownAverages,
                    fn (array $average): bool => $average['candidato_politico_id'] !== null
                        ? in_array($average['candidato_politico_id'], $latestCandidateIds, true)
                        : in_array($average['external_candidate_id'], $latestExternalCandidateIds, true),
                ));
            }

            $hasOwnAggregate = $ownAverages !== []
                && max(array_column($ownAverages, 'pesquisas_incluidas')) > 1;

            if ($hasOwnAggregate) {
                $hasAggregate = true;
                $serializedAverages = array_map(fn (array $average): array => [
                    'candidate_id' => $average['candidato_politico_id'],
                    'external_candidate_id' => $average['external_candidate_id'],
                    'name' => $average['nome'],
                    'party' => $average['partido'],
                    'party_color' => $colorForParty($average['partido']),
                    'percentage' => $average['media'],
                    'confidence_interval_low' => null,
                    'confidence_interval_high' => null,
                    'polls_included' => $average['pesquisas_incluidas'],
                    'total_sample_size' => $average['amostra_total'],
                    'calculated_at' => now()->toIso8601String(),
                    'source_url' => null,
                    'source' => 'govnexgab',
                    'is_favorite' => $average['candidato_politico_id'] !== null
                        && in_array($average['candidato_politico_id'], $favoriteIds, true),
                ], $ownAverages);
            } elseif ($round === 2) {
                // As médias do ElectioLab não distinguem turno — agregam as
                // pesquisas de 1º turno. No 2º turno só vale a média própria,
                // calculada acima sobre as pesquisas de 2º turno.
                $hasAggregate = false;
                $serializedAverages = [];
            } else {
                $officeAverages = MediaPesquisaEleitoral::query()
                    ->where('eleicao_id', $election->id)
                    ->where('uf', $dataState)
                    ->when(
                        $dataCity !== null,
                        fn (Builder $query) => $query->where('municipio', $dataCity),
                        fn (Builder $query) => $query->whereNull('municipio'),
                    )
                    ->where('cargo', $officeType)
                    // Restringe às candidaturas da última pesquisa individual
                    // quando ela trouxe resultados — mas o ElectioLab publica
                    // pesquisas de presidente sem o detalhamento por candidato
                    // (só a média consolidada), e nesse caso não há nada para
                    // restringir: aplicar o filtro vazio zeraria médias reais e
                    // já calculadas.
                    ->when(
                        $latestExternalCandidateIds !== [],
                        fn (Builder $query) => $query
                            ->whereIn('external_candidate_id', $latestExternalCandidateIds),
                    )
                    ->orderByDesc('media_ponderada')
                    ->get();
                $hasAggregate = (int) $officeAverages->max('pesquisas_incluidas') > 1;
                $serializedAverages = $officeAverages->map(fn (MediaPesquisaEleitoral $average): array => [
                    'candidate_id' => $average->candidato_politico_id,
                    'external_candidate_id' => $average->external_candidate_id,
                    'name' => $average->candidato_nome,
                    'party' => $average->partido_sigla,
                    'party_color' => $colorForParty($average->partido_sigla),
                    'percentage' => $average->media_ponderada,
                    'confidence_interval_low' => $average->intervalo_confianca_min,
                    'confidence_interval_high' => $average->intervalo_confianca_max,
                    'polls_included' => $average->pesquisas_incluidas,
                    'total_sample_size' => $average->amostra_total,
                    'calculated_at' => $average->calculada_em?->toIso8601String(),
                    'source_url' => $average->fonte_url,
                    'source' => 'electiolab',
                    'is_favorite' => $average->candidato_politico_id !== null
                        && in_array($average->candidato_politico_id, $favoriteIds, true),
                ])->all();
            }

            if (! $hasAggregate) {
                $serializedAverages = [];
            }

            $serializedPolls = [];

            foreach ($officePolls as $poll) {
                $serializedResults = [];

                foreach ($poll->resultados as $result) {
                    $serializedResults[] = [
                        'candidate_id' => $result->candidato_politico_id,
                        'external_candidate_id' => $result->external_candidate_id,
                        'name' => $result->candidato_nome,
                        'party' => $result->partido_sigla,
                        'party_color' => $colorForParty($result->partido_sigla),
                        'percentage' => $result->percentual,
                        'is_favorite' => $result->candidato_politico_id !== null
                            && in_array($result->candidato_politico_id, $favoriteIds, true),
                    ];
                }

                $serializedPolls[] = [
                    'id' => $poll->id,
                    'external_id' => $poll->external_id,
                    'institute' => $poll->instituto ?? 'Instituto não informado',
                    'publication_date' => $poll->publicada_em->toDateString(),
                    'fieldwork_start' => $poll->coleta_inicio_em?->toDateString(),
                    'fieldwork_end' => $poll->coleta_fim_em?->toDateString(),
                    'sample_size' => $poll->tamanho_amostra,
                    'margin_of_error' => $poll->margem_erro,
                    'methodology' => $poll->metodologia,
                    'scope' => $poll->abrangencia,
                    'poll_type' => $poll->tipo,
                    'source_url' => $poll->fonte_url,
                    'source_updated_at' => $poll->fonte_atualizada_em?->toIso8601String(),
                    'results' => $serializedResults,
                ];
            }

            $available = $available || $serializedPolls !== [];
            $offices[] = [
                'slug' => $officeType,
                'label' => $label,
                'round' => $round,
                'scope_label' => match (true) {
                    $officeType === 'prefeito' => "{$office->municipio}/".mb_strtoupper($office->estado),
                    $dataState === 'BR' => 'Brasil',
                    default => $dataState,
                },
                'senate_notice' => $officeType === 'senador' && $election->ano === 2026
                    ? 'Em 2026, cada eleitor escolhe dois candidatos ao Senado. Os percentuais podem ultrapassar 100% quando somados.'
                    : null,
                'has_aggregate' => $hasAggregate,
                'polls' => $serializedPolls,
                'averages' => $serializedAverages,
            ];
        }

        return [
            ...$empty,
            'available' => $available,
            'offices' => $offices,
        ];
    }

    /** @return Collection<int, PesquisaEleitoral> */
    private function officePolls(Eleicao $election, string $officeType, int $round, string $uf, ?string $municipio): Collection
    {
        return PesquisaEleitoral::query()
            ->with(['resultados' => fn ($query) => $query->orderByDesc('percentual')])
            ->where('eleicao_id', $election->id)
            ->where('turno', $round)
            ->where('uf', $uf)
            ->when(
                $municipio !== null,
                fn (Builder $query) => $query->where('municipio', $municipio),
                fn (Builder $query) => $query->whereNull('municipio'),
            )
            ->where('cargo', $officeType)
            ->whereDate('publicada_em', '<=', today())
            ->where(fn (Builder $query) => $query
                ->whereNull('coleta_inicio_em')
                ->orWhereDate('coleta_inicio_em', '<=', today()))
            ->where(fn (Builder $query) => $query
                ->whereNull('coleta_fim_em')
                ->orWhereDate('coleta_fim_em', '<=', today()))
            ->orderByDesc('publicada_em')
            ->orderByDesc('coleta_fim_em')
            ->get();
    }

    /**
     * `$officeId` só deve ser informado para datasets realmente vinculados a
     * um gabinete específico (hoje, só `pollingdata_polls`) — os demais
     * datasets do TSE (`electorate`, `candidates`, `turnout`,
     * `candidate_votes`) cobrem o Brasil inteiro de uma vez só e por isso
     * são sempre gravados com `gabinete_id` nulo (ver
     * ElectionLifecycleService::syncDataset()); filtrar essas consultas pelo
     * gabinete do usuário nunca encontraria a sincronização real, deixando o
     * indicador preso em "ainda não sincronizado" mesmo após um sync global
     * concluído.
     *
     * @return array<string, mixed>|null
     */
    private function latestSync(string $dataset, ?int $officeId): ?array
    {
        $sync = SincronizacaoTse::query()
            ->where('dataset', $dataset)
            ->when(
                $officeId !== null,
                fn (Builder $query) => $query->where('gabinete_id', $officeId),
                fn (Builder $query) => $query->whereNull('gabinete_id'),
            )
            ->latest('iniciada_em')
            ->first();

        return $sync ? [
            'status' => $sync->situacao,
            'processed' => $sync->registros_processados,
            'started_at' => $sync->iniciada_em->toIso8601String(),
            'completed_at' => $sync->concluida_em?->toIso8601String(),
        ] : null;
    }
}
