<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OfficeModule;
use App\Exceptions\ElectionLifecycleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreElectionRequest;
use App\Http\Requests\Admin\SyncElectionDatasetRequest;
use App\Http\Requests\Admin\UpdateElectionRequest;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\TseSync;
use App\Services\Modules\OfficeModuleManager;
use App\Services\Politics\ElectionLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A eleição como um corpo só: o cadastro (municipal ou geral), os datasets
 * do TSE que valem para o tipo dela e a apuração ficam na mesma tela
 * (`admin/elections/show`). As ações aqui são finas — a regra de cada etapa
 * está no ElectionLifecycleService.
 */
class ElectionController extends Controller
{
    /** Complemento da mensagem de confirmação, por dataset. */
    private const SUBJECTS = [
        'municipalities' => 'da base de municípios',
        'electorate' => 'do eleitorado de :ano',
        'candidates' => 'das candidaturas de :ano',
        'turnout' => 'do comparecimento de :ano',
        'candidate_votes' => 'da votação nominal de :ano',
        'polling_locations' => 'dos locais de votação de :ano',
        'section_votes' => 'da votação por seção de :ano',
        'poll_registry' => 'do registro de pesquisas de :ano',
    ];

    public function __construct(
        private readonly ElectionLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        return Inertia::render('admin/elections/index', [
            'elections' => Election::query()
                ->withCount('candidatos')
                ->orderByDesc('ano')
                ->orderBy('tipo')
                ->get()
                ->map(fn (Election $election): array => [
                    ...$this->summary($election),
                    'candidates_count' => (int) $election->getAttribute('candidatos_count'),
                ])
                ->values(),
        ]);
    }

    public function show(Request $request, Election $eleicao, OfficeModuleManager $modules): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        return Inertia::render('admin/elections/show', [
            'election' => $this->summary($eleicao),
            'politicsAvailable' => $modules->anyActiveOffice(OfficeModule::Politics),
            'datasets' => $this->lifecycle->datasets($eleicao),
            'tally' => [
                'available' => $this->lifecycle->canTally($eleicao),
                'offices' => $this->lifecycle->tallySummary($eleicao),
            ],
        ]);
    }

    public function store(StoreElectionRequest $request): RedirectResponse
    {
        $election = Election::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Eleição cadastrada.']);

        // Próximo passo do ciclo: sincronizar os datasets, na tela dela.
        return to_route('admin.elections.show', $election);
    }

    public function update(UpdateElectionRequest $request, Election $eleicao): RedirectResponse
    {
        $eleicao->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Eleição atualizada.']);

        return to_route('admin.elections.show', $eleicao);
    }

    public function destroy(Request $request, Election $eleicao): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        // Uma eleição com candidatos já sincronizados alimenta todo o
        // histórico do painel político — excluí-la apagaria esses dados em
        // cascata (ver constraint em candidatos_politicos.eleicao_id).
        if (Candidate::query()->where('eleicao_id', $eleicao->id)->exists()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Esta eleição já tem candidatos sincronizados e não pode ser removida.',
            ]);

            return to_route('admin.elections.index');
        }

        $eleicao->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Eleição removida.']);

        return to_route('admin.elections.index');
    }

    /** Põe na fila um dataset do TSE para o ano da eleição. */
    public function syncDataset(SyncElectionDatasetRequest $request, Election $eleicao): RedirectResponse
    {
        $dataset = (string) $request->validated('dataset');

        try {
            $run = $this->lifecycle->syncDataset($eleicao, $dataset, $request->user());
        } catch (ElectionLifecycleException $exception) {
            return $this->reject($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sincronização '.str_replace(':ano', (string) $run->ano, self::SUBJECTS[$dataset]).' via GOVNEX API adicionada à fila.',
        ]);

        return back();
    }

    /** Cancela uma execução travada de um dataset da eleição. */
    public function cancelSync(Request $request, Election $eleicao, TseSync $sync): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);
        abort_unless($this->lifecycle->belongsTo($eleicao, $sync), 404);

        try {
            $this->lifecycle->cancelSync($eleicao, $sync, $request->user());
        } catch (ElectionLifecycleException $exception) {
            return $this->reject($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sincronização cancelada. Já é possível sincronizar o dataset de novo.',
        ]);

        return back();
    }

    /**
     * Põe a apuração da eleição na fila `tse` — a mesma que roda sozinha de
     * hora em hora (`politics:tally-elections`) e como efeito colateral da
     * tela de apuração, só que disparável a qualquer momento pelo admin.
     */
    public function apurar(Request $request, Election $eleicao): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);

        try {
            $this->lifecycle->requestTally($eleicao);
        } catch (ElectionLifecycleException $exception) {
            return $this->reject($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Apuração disparada para {$eleicao->nome}.",
        ]);

        return back();
    }

    /** @return array<string, mixed> */
    private function summary(Election $election): array
    {
        return [
            'id' => $election->id,
            'codigo_tse' => $election->codigo_tse,
            'ano' => $election->ano,
            'tipo' => $election->tipo->value,
            'nome' => $election->nome,
            'primeiro_turno_em' => $election->primeiro_turno_em->toDateString(),
            'segundo_turno_em' => $election->segundo_turno_em?->toDateString(),
            'situacao' => $election->situacao,
        ];
    }

    /**
     * Recusa de um campo vira erro de validação (aparece na linha); recusa de
     * estado, um toast.
     */
    private function reject(ElectionLifecycleException $exception): RedirectResponse
    {
        if ($exception->field !== null) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

        return back();
    }
}
