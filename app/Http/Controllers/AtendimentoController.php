<?php

namespace App\Http\Controllers;

use App\Enums\GabineteModule;
use App\Enums\UserRole;
use App\Http\Requests\Attendances\AttendanceRequest;
use App\Models\Atendimento;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AtendimentoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Atendimento::class);

        $filters = [
            'q' => trim($request->string('q')->toString()),
            'atendente_id' => $request->integer('atendente_id') ?: null,
            'de' => $request->string('de')->toString(),
            'ate' => $request->string('ate')->toString(),
            'retorno' => $request->boolean('retorno'),
        ];

        $attendances = Atendimento::query()
            ->select([
                'id',
                'cidadao_id',
                'atendente_id',
                'demanda_id',
                'assunto',
                'atendido_em',
                'duracao_minutos',
                'requer_retorno',
                'retorno_previsto_em',
            ])
            ->with([
                'cidadao:id,nome,eleitor',
                'atendente:id,name',
                'demanda:id,protocolo,titulo',
            ])
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['q'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('assunto', 'like', "%{$search}%")
                        ->orWhere('relato', 'like', "%{$search}%")
                        ->orWhereHas('cidadao', fn (Builder $citizens) => $citizens
                            ->where('nome', 'like', "%{$search}%"));
                });
            })
            ->when($filters['atendente_id'], fn (Builder $query) => $query
                ->where('atendente_id', $filters['atendente_id']))
            ->when($filters['de'] !== '', fn (Builder $query) => $query
                ->whereDate('atendido_em', '>=', $filters['de']))
            ->when($filters['ate'] !== '', fn (Builder $query) => $query
                ->whereDate('atendido_em', '<=', $filters['ate']))
            ->when($filters['retorno'], fn (Builder $query) => $query
                ->where('requer_retorno', true))
            ->latest('atendido_em')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('attendances/index', [
            'attendances' => $attendances,
            'filters' => $filters,
            'members' => $this->members($request),
            'canDelete' => $this->canDelete($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Atendimento::class);

        $selectedCitizenId = Cidadao::query()
            ->whereKey($request->integer('cidadao_id'))
            ->value('id');

        return Inertia::render('attendances/create', [
            'options' => $this->formOptions($request),
            'defaults' => [
                'citizenId' => $selectedCitizenId,
                'attendantId' => $request->user()->id,
                'attendedAt' => $this->localDateTime(now(), $request),
            ],
        ]);
    }

    public function store(AttendanceRequest $request): RedirectResponse
    {
        $data = $this->normalizeDates($request->validated(), $request);
        $attendance = Atendimento::query()->create([
            ...$data,
            'criado_por_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Atendimento presencial registrado.',
        ]);

        return to_route('attendances.show', $attendance);
    }

    public function show(Request $request, Atendimento $atendimento): Response
    {
        $this->authorize('view', $atendimento);
        $atendimento->load([
            'cidadao:id,nome,telefone,whatsapp,email,eleitor',
            'atendente:id,name,email',
            'demanda:id,protocolo,titulo,status',
            'criadoPor:id,name',
        ]);

        return Inertia::render('attendances/show', [
            'attendance' => $atendimento,
            'canDelete' => $this->canDelete($request),
        ]);
    }

    public function edit(Request $request, Atendimento $atendimento): Response
    {
        $this->authorize('update', $atendimento);

        return Inertia::render('attendances/edit', [
            'attendance' => $atendimento,
            'attendedAtLocal' => $this->localDateTime($atendimento->atendido_em, $request),
            'options' => $this->formOptions($request),
        ]);
    }

    public function update(
        AttendanceRequest $request,
        Atendimento $atendimento,
    ): RedirectResponse {
        $atendimento->update($this->normalizeDates($request->validated(), $request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Atendimento atualizado.',
        ]);

        return to_route('attendances.show', $atendimento);
    }

    public function destroy(Atendimento $atendimento): RedirectResponse
    {
        $this->authorize('delete', $atendimento);
        $atendimento->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Atendimento excluído.',
        ]);

        return to_route('attendances.index');
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        $demandsEnabled = app(GabineteModuleManager::class)
            ->isActive($request->user()->gabinete_id, GabineteModule::Demands);

        return [
            'citizens' => Cidadao::query()
                ->select(['id', 'nome', 'eleitor'])
                ->orderBy('nome')
                ->limit(1000)
                ->get(),
            'members' => $this->members($request),
            'demands' => $demandsEnabled
                ? Demanda::query()
                    ->select(['id', 'cidadao_id', 'protocolo', 'titulo'])
                    ->latest('aberta_em')
                    ->limit(1000)
                    ->get()
                : [],
            'capabilities' => [
                'demands' => $demandsEnabled,
            ],
        ];
    }

    /** @return Collection<int, User> */
    private function members(Request $request): Collection
    {
        return User::query()
            ->where('gabinete_id', $request->user()->gabinete_id)
            ->where('role', '!=', UserRole::Root)
            ->where('is_active', true)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDates(array $data, Request $request): array
    {
        $timezone = $request->user()->gabinete->timezone ?? 'America/Sao_Paulo';
        $data['atendido_em'] = CarbonImmutable::parse(
            (string) $data['atendido_em'],
            $timezone,
        )->utc();

        if (! ($data['requer_retorno'] ?? false)) {
            $data['retorno_previsto_em'] = null;
        }

        return $data;
    }

    private function localDateTime(
        \DateTimeInterface $date,
        Request $request,
    ): string {
        $timezone = $request->user()->gabinete->timezone ?? 'America/Sao_Paulo';

        return CarbonImmutable::instance($date)
            ->setTimezone($timezone)
            ->format('Y-m-d\TH:i');
    }

    private function canDelete(Request $request): bool
    {
        return in_array(
            $request->user()->role,
            [UserRole::Councilor, UserRole::ChiefOfStaff],
            true,
        );
    }
}
