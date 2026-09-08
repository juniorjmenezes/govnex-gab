<?php

namespace App\Http\Controllers;

use App\Actions\Demands\CloseDemand;
use App\Actions\Demands\CompleteNextAction;
use App\Actions\Demands\CreateDemand;
use App\Actions\Demands\ReopenDemand;
use App\Actions\Demands\ResolveDemand;
use App\Actions\Demands\SetNextAction;
use App\Actions\Demands\ToggleDemandFavorite;
use App\Actions\Demands\TransitionDemandStatus;
use App\Actions\Demands\UpdateDemand;
use App\Enums\DemandEventType;
use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandResultado;
use App\Enums\DemandStatus;
use App\Enums\UserRole;
use App\Http\Requests\Demands\CloseDemandRequest;
use App\Http\Requests\Demands\ReopenDemandRequest;
use App\Http\Requests\Demands\ResolveDemandRequest;
use App\Http\Requests\Demands\SetNextActionRequest;
use App\Http\Requests\Demands\StoreDemandRequest;
use App\Http\Requests\Demands\TransitionDemandStatusRequest;
use App\Http\Requests\Demands\UpdateDemandRequest;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DemandaController extends Controller
{
    /** @var list<string> */
    private const TABS = ['inbox', 'mine', 'awaiting', 'today', 'overdue'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Demanda::class);
        $user = $request->user();

        $tab = in_array($request->string('tab')->toString(), self::TABS, true)
            ? $request->string('tab')->toString()
            : 'inbox';

        $filters = [
            'tab' => $tab,
            'q' => trim((string) $request->string('q')),
            'status' => $request->string('status')->toString(),
            'prioridade' => $request->string('prioridade')->toString(),
            'categoria_id' => $request->integer('categoria_id') ?: null,
            'bairro_id' => $request->integer('bairro_id') ?: null,
            'responsavel_id' => $request->integer('responsavel_id') ?: null,
            'origem' => $request->string('origem')->toString(),
            'aberta_de' => $request->string('aberta_de')->toString(),
            'aberta_ate' => $request->string('aberta_ate')->toString(),
            'sem_responsavel' => $request->boolean('sem_responsavel'),
            'sort' => $request->string('sort', 'ultima_atividade_em')->toString(),
            'direction' => $request->string('direction', 'desc')->toString(),
            'per_page' => $request->integer('per_page', 15),
        ];

        $sorts = [
            'protocolo' => 'protocolo',
            'titulo' => 'titulo',
            'status' => 'status',
            'prioridade' => 'prioridade',
            'prazo' => 'prazo',
            'aberta_em' => 'aberta_em',
            'ultima_atividade_em' => 'ultima_atividade_em',
            'proxima_acao_data' => 'proxima_acao_data',
        ];
        $sort = $sorts[$filters['sort']] ?? 'ultima_atividade_em';
        $direction = $filters['direction'] === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($filters['per_page'], [15, 30, 50], true) ? $filters['per_page'] : 15;

        $demands = $this->baseQuery()
            ->tap(fn (Builder $query) => $this->applyTab($query, $tab, $user))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['q'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('protocolo', 'like', "%{$search}%")
                        ->orWhere('titulo', 'like', "%{$search}%")
                        ->orWhere('descricao', 'like', "%{$search}%")
                        ->orWhereHas('cidadao', fn (Builder $citizens) => $citizens->where('nome', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['prioridade'] !== '', fn (Builder $query) => $query->where('prioridade', $filters['prioridade']))
            ->when($filters['categoria_id'], fn (Builder $query) => $query->where('categoria_id', $filters['categoria_id']))
            ->when($filters['bairro_id'], fn (Builder $query) => $query->where('bairro_id', $filters['bairro_id']))
            ->when($filters['responsavel_id'], fn (Builder $query) => $query->where('responsavel_id', $filters['responsavel_id']))
            ->when($filters['origem'] !== '', fn (Builder $query) => $query->where('origem', $filters['origem']))
            ->when($filters['aberta_de'] !== '', fn (Builder $query) => $query->whereDate('aberta_em', '>=', $filters['aberta_de']))
            ->when($filters['aberta_ate'] !== '', fn (Builder $query) => $query->whereDate('aberta_em', '<=', $filters['aberta_ate']))
            ->when($filters['sem_responsavel'], fn (Builder $query) => $query->whereNull('responsavel_id'))
            ->orderByRaw('favoritada_em is null')
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Demanda $demand): array => [
                ...$demand->toArray(),
                'atrasada' => $demand->isOverdue(),
                'proxima_acao_atrasada' => $demand->isNextActionOverdue(),
            ]);

        return Inertia::render('demands/index', [
            'demands' => $demands,
            'canDelete' => in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true),
            'filters' => $filters,
            'tabCounts' => $this->tabCounts($user),
            'options' => $this->filterOptions(),
        ]);
    }

    /** @return Builder<Demanda> */
    private function baseQuery(): Builder
    {
        return Demanda::query()
            ->select([
                'id', 'protocolo', 'titulo', 'status', 'prioridade', 'origem',
                'cidadao_id', 'categoria_id', 'bairro_id', 'responsavel_id',
                'aberta_em', 'prazo', 'concluida_em', 'ultima_atividade_em',
                'proxima_acao_descricao', 'proxima_acao_data', 'proxima_acao_responsavel_id', 'proxima_acao_concluida_em',
                'favoritada_em', 'favoritada_por_id',
            ])
            ->with([
                'cidadao:id,nome',
                'categoria:id,nome,icone,cor_semantica',
                'bairro:id,nome',
                'responsavel:id,name',
                'favoritadaPor:id,name',
            ]);
    }

    /** @param Builder<Demanda> $query */
    private function applyTab(Builder $query, string $tab, User $user): void
    {
        $today = Carbon::today();

        match ($tab) {
            'mine' => $query->where('responsavel_id', $user->id)->whereIn('status', DemandStatus::openValues()),
            'awaiting' => $query->where('status', DemandStatus::Awaiting),
            'today' => $query->whereNull('proxima_acao_concluida_em')->whereDate('proxima_acao_data', $today),
            'overdue' => $query->where(function (Builder $query) use ($today): void {
                $query->where(function (Builder $query): void {
                    $query->whereIn('status', DemandStatus::openValues())
                        ->whereNotNull('prazo')
                        ->where('prazo', '<', now());
                })->orWhere(function (Builder $query) use ($today): void {
                    $query->whereNull('proxima_acao_concluida_em')
                        ->whereNotNull('proxima_acao_data')
                        ->whereDate('proxima_acao_data', '<', $today);
                });
            }),
            default => $query->whereIn('status', DemandStatus::openValues()),
        };
    }

    /** @return array<string, int> */
    private function tabCounts(User $user): array
    {
        return collect(self::TABS)->mapWithKeys(function (string $tab) use ($user): array {
            $query = $this->baseQuery();
            $this->applyTab($query, $tab, $user);

            return [$tab => $query->count()];
        })->all();
    }

    public function kanban(Request $request): Response
    {
        $this->authorize('viewAny', Demanda::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $search = trim((string) $request->string('q'));
        $requestedPriority = $request->string('prioridade')->toString();
        $priorityValue = in_array($requestedPriority, array_column(DemandPriority::cases(), 'value'), true)
            ? $requestedPriority
            : '';
        $responsibleId = $request->integer('responsavel_id') ?: null;

        $columns = collect(DemandStatus::cases())->map(function (DemandStatus $status) use ($search, $priorityValue, $responsibleId): array {
            $query = Demanda::query()
                ->select([
                    'id', 'gabinete_id', 'protocolo', 'titulo', 'status', 'prioridade',
                    'cidadao_id', 'categoria_id', 'bairro_id', 'responsavel_id',
                    'aberta_em', 'prazo', 'concluida_em',
                ])
                ->where('status', $status)
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('protocolo', 'like', "%{$search}%")
                            ->orWhere('titulo', 'like', "%{$search}%")
                            ->orWhereHas('cidadao', fn (Builder $citizens) => $citizens->where('nome', 'like', "%{$search}%"));
                    });
                })
                ->when($priorityValue !== '', fn (Builder $query) => $query->where('prioridade', $priorityValue))
                ->when($responsibleId, fn (Builder $query) => $query->where('responsavel_id', $responsibleId));

            $total = (clone $query)->count();
            $demands = $query
                ->with([
                    'cidadao:id,nome',
                    'categoria:id,nome,icone,cor_semantica',
                    'bairro:id,nome',
                    'responsavel:id,name',
                ])
                ->withCount('anexos')
                ->orderByRaw('prazo IS NULL')
                ->orderBy('prazo')
                ->orderByDesc('aberta_em')
                ->limit(60)
                ->get()
                ->map(fn (Demanda $demand): array => [
                    ...$demand->toArray(),
                    'atrasada' => $demand->isOverdue(),
                    'allowed_transitions' => collect($demand->status->allowedTransitions())
                        ->map(fn (DemandStatus $target) => $target->value)
                        ->values()
                        ->all(),
                ]);

            return [
                'status' => $status->value,
                'label' => $status->label(),
                'total' => $total,
                'truncated' => $total > $demands->count(),
                'demands' => $demands,
            ];
        });

        $statusTransitions = collect(DemandStatus::cases())->mapWithKeys(
            fn (DemandStatus $status): array => [
                $status->value => collect($status->allowedTransitions())
                    ->map(fn (DemandStatus $target) => $target->value)
                    ->values()
                    ->all(),
            ],
        );

        return Inertia::render('demands/kanban', [
            'columns' => $columns,
            'transitions' => $statusTransitions,
            'filters' => [
                'q' => $search,
                'prioridade' => $priorityValue,
                'responsavel_id' => $responsibleId,
            ],
            'options' => [
                'priorities' => $this->enumOptions(DemandPriority::cases()),
                'members' => User::query()
                    ->where('gabinete_id', $user->gabinete_id)
                    ->where('role', '!=', UserRole::Root)
                    ->where('is_active', true)
                    ->select(['id', 'name'])
                    ->orderBy('name')
                    ->get(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Demanda::class);

        return Inertia::render('demands/create', [
            'options' => $this->formOptions(),
            'officeLocation' => $request->user()->gabinete()->firstOrFail()->only(['estado', 'municipio']),
        ]);
    }

    public function store(StoreDemandRequest $request, CreateDemand $action): RedirectResponse
    {
        $demand = $action->handle($request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Demanda {$demand->protocolo} criada."]);

        return to_route('demands.show', $demand);
    }

    public function show(Demanda $demanda): Response
    {
        $this->authorize('view', $demanda);
        $demanda->load([
            'cidadao:id,nome,telefone,whatsapp,email,consentimento_contato',
            'categoria:id,nome,icone,cor_semantica',
            'bairro:id,nome,municipio,estado',
            'responsavel:id,name,email',
            'proximaAcaoResponsavel:id,name',
            'favoritadaPor:id,name',
            'criadoPor:id,name',
            'eventos' => fn ($query) => $query
                ->with(['usuario:id,name', 'anexos', 'retornoDe:id,destino'])
                ->latest('created_at')
                ->limit(200),
            'anexos' => fn ($query) => $query->with('usuario:id,name')->latest(),
        ]);

        return Inertia::render('demands/show', [
            'demand' => [
                ...$demanda->toArray(),
                'atrasada' => $demanda->isOverdue(),
                'proxima_acao_atrasada' => $demanda->isNextActionOverdue(),
            ],
            'allowedTransitions' => collect($demanda->status->allowedTransitions())
                ->map(fn (DemandStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->values(),
            'resultados' => $this->enumOptions(DemandResultado::cases()),
            'pendingReferrals' => $demanda->eventos
                ->where('tipo', DemandEventType::Encaminhamento)
                ->whereNull('retorno_recebido_em')
                ->map(fn (DemandaEvento $event): array => ['id' => $event->id, 'destino' => $event->destino])
                ->values(),
            'canDelete' => request()->user()->can('delete', $demanda),
            'members' => $this->filterOptions()['members'],
        ]);
    }

    public function edit(Demanda $demanda): Response
    {
        $this->authorize('update', $demanda);

        return Inertia::render('demands/edit', [
            'demand' => $demanda,
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateDemandRequest $request, Demanda $demanda, UpdateDemand $action): RedirectResponse
    {
        $action->handle($demanda, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Demanda atualizada.']);

        return to_route('demands.show', $demanda);
    }

    public function transition(
        TransitionDemandStatusRequest $request,
        Demanda $demanda,
        TransitionDemandStatus $action,
    ): RedirectResponse {
        $status = DemandStatus::from($request->validated('status'));
        $action->handle($demanda, $status, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Status alterado para {$status->label()}."]);

        return to_route('demands.show', $demanda);
    }

    public function transitionFromKanban(
        TransitionDemandStatusRequest $request,
        Demanda $demanda,
        TransitionDemandStatus $action,
    ): RedirectResponse {
        $status = DemandStatus::from($request->validated('status'));
        $action->handle($demanda, $status, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Demanda movida para {$status->label()}."]);

        return back();
    }

    public function resolve(ResolveDemandRequest $request, Demanda $demanda, ResolveDemand $action): RedirectResponse
    {
        $resultado = $request->validated('resultado');
        $action->handle(
            $demanda,
            $request->user(),
            $resultado ? DemandResultado::from($resultado) : null,
            $request->validated('descricao'),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Demanda marcada como resolvida.']);

        return to_route('demands.show', $demanda);
    }

    public function close(CloseDemandRequest $request, Demanda $demanda, CloseDemand $action): RedirectResponse
    {
        $action->handle($demanda, $request->user(), $request->validated('descricao'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Demanda encerrada.']);

        return to_route('demands.show', $demanda);
    }

    public function reopen(ReopenDemandRequest $request, Demanda $demanda, ReopenDemand $action): RedirectResponse
    {
        $action->handle($demanda, $request->user(), $request->validated('motivo'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Demanda reaberta.']);

        return to_route('demands.show', $demanda);
    }

    public function setNextAction(SetNextActionRequest $request, Demanda $demanda, SetNextAction $action): RedirectResponse
    {
        $responsibleId = $request->validated('responsavel_id');
        $action->handle(
            $demanda,
            $request->user(),
            $request->validated('descricao'),
            $request->validated('data') ? Carbon::parse($request->validated('data')) : null,
            $responsibleId ? User::query()->where('id', $responsibleId)->first() : null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Próxima ação definida.']);

        return to_route('demands.show', $demanda);
    }

    public function completeNextAction(Demanda $demanda, CompleteNextAction $action): RedirectResponse
    {
        $this->authorize('update', $demanda);
        $action->handle($demanda, request()->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Próxima ação concluída.']);

        return to_route('demands.show', $demanda);
    }

    public function toggleFavorite(Demanda $demanda, ToggleDemandFavorite $action): RedirectResponse
    {
        $this->authorize('update', $demanda);
        $action->handle($demanda, request()->user());

        return back();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            ...$this->filterOptions(),
            'creationPriorities' => $this->enumOptions([DemandPriority::Normal, DemandPriority::High, DemandPriority::Urgent]),
            'citizens' => Cidadao::query()->select(['id', 'nome', 'bairro_id', 'endereco', 'numero', 'complemento', 'ponto_referencia'])
                ->orderBy('nome')->limit(500)->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'statuses' => $this->enumOptions(DemandStatus::cases()),
            'priorities' => $this->enumOptions(DemandPriority::cases()),
            'origins' => $this->enumOptions(DemandOrigin::cases()),
            'categories' => Categoria::query()
                ->select(['id', 'nome', 'icone', 'cor_semantica'])
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(),
            'citizens' => Cidadao::query()->select(['id', 'nome'])->orderBy('nome')->limit(500)->get(),
            'neighborhoods' => Bairro::query()->select(['id', 'nome'])->where('ativo', true)->orderBy('nome')->get(),
            'members' => User::query()
                ->where('gabinete_id', request()->user()->gabinete_id)
                ->where('role', '!=', UserRole::Root)
                ->where('is_active', true)
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * @param  Collection<int, DemandStatus|DemandPriority|DemandOrigin|DemandResultado>|array<int, DemandStatus|DemandPriority|DemandOrigin|DemandResultado>  $cases
     * @return Collection<int, array{value: string, label: string}>
     */
    private function enumOptions(Collection|array $cases): Collection
    {
        return collect($cases)->map(fn (DemandStatus|DemandPriority|DemandOrigin|DemandResultado $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ]);
    }

    public function destroy(Demanda $demanda): RedirectResponse
    {
        $this->authorize('delete', $demanda);
        $demanda->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Demanda {$demanda->protocolo} excluída."]);

        return to_route('demands.index');
    }
}
