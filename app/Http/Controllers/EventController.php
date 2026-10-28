<?php

namespace App\Http\Controllers;

use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\UserRole;
use App\Http\Requests\Events\EventRequest;
use App\Models\Citizen;
use App\Models\Event;
use App\Models\User;
use App\Support\PerPage;
use App\Tenancy\OfficeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Event::class);

        $filters = [
            'q' => trim($request->string('q')->toString()),
            'tipo' => $request->string('tipo')->toString(),
            'status' => $request->string('status')->toString(),
            'duracao' => $request->string('duracao')->toString(),
            'responsavel_id' => $request->integer('responsavel_id') ?: null,
            'de' => $request->string('de')->toString(),
            'ate' => $request->string('ate')->toString(),
            'per_page' => $request->integer('per_page', 15),
        ];
        $perPage = PerPage::resolve($request, 15);

        $events = Event::query()
            ->select([
                'id',
                'titulo',
                'tipo',
                'status',
                'duracao',
                'inicio_em',
                'fim_em',
                'local',
                'responsavel_id',
            ])
            ->with('responsavel:id,name')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['q'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('titulo', 'like', "%{$search}%")
                        ->orWhere('descricao', 'like', "%{$search}%")
                        ->orWhere('local', 'like', "%{$search}%");
                });
            })
            ->when($filters['tipo'] !== '', fn (Builder $query) => $query
                ->where('tipo', $filters['tipo']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query
                ->where('status', $filters['status']))
            ->when($filters['duracao'] !== '', fn (Builder $query) => $query
                ->where('duracao', $filters['duracao']))
            ->when($filters['responsavel_id'], fn (Builder $query) => $query
                ->where('responsavel_id', $filters['responsavel_id']))
            ->when($filters['de'] !== '', fn (Builder $query) => $query
                ->whereDate('fim_em', '>=', $filters['de']))
            ->when($filters['ate'] !== '', fn (Builder $query) => $query
                ->whereDate('inicio_em', '<=', $filters['ate']))
            ->orderBy('inicio_em')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('events/index', [
            'events' => $events,
            'filters' => $filters,
            'options' => $this->options($request),
            'canDelete' => $this->canDelete($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Event::class);
        $timezone = $this->timezone($request);
        $start = CarbonImmutable::now($timezone)->addHour()->startOfHour();

        return Inertia::render('events/create', [
            'options' => $this->options($request),
            'defaults' => [
                'responsibleId' => $request->user()->id,
                'start' => $this->dateTimeParts($start),
                'end' => $this->dateTimeParts($start->addHours(2)),
            ],
        ]);
    }

    public function citizenParticipants(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);
        $query = trim($request->string('q')->toString());

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Citizen::query()
                ->select(['id', 'nome'])
                ->where('nome', 'like', "%{$query}%")
                ->orderBy('nome')
                ->limit(20)
                ->get(),
        );
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $userParticipants = array_map('intval', $data['participantes_usuarios'] ?? []);
        $citizenParticipants = array_map('intval', $data['participantes_cidadaos'] ?? []);
        unset($data['participantes_usuarios'], $data['participantes_cidadaos']);

        $event = DB::transaction(function () use (
            $request,
            $data,
            $userParticipants,
            $citizenParticipants,
        ): Event {
            $event = Event::query()->create([
                ...$this->normalizeDates($data, $request),
                'criado_por_id' => $request->user()->id,
            ]);
            $event->participantesUsuarios()->sync($userParticipants);
            $event->participantesCidadaos()->sync($citizenParticipants);

            return $event;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Evento criado.',
        ]);

        return to_route('events.show', $event);
    }

    public function show(Request $request, Event $evento): Response
    {
        $this->authorize('view', $evento);
        $evento->load([
            'responsavel:id,name,email',
            'criadoPor:id,name',
            'participantesUsuarios:id,name,email',
            'participantesCidadaos:id,nome,email,telefone,whatsapp',
        ]);

        return Inertia::render('events/show', [
            'event' => $evento,
            'canDelete' => $this->canDelete($request),
        ]);
    }

    public function edit(Request $request, Event $evento): Response
    {
        $this->authorize('update', $evento);
        $evento->load([
            'participantesUsuarios:id,name',
            'participantesCidadaos:id,nome',
        ]);

        return Inertia::render('events/edit', [
            'event' => $evento,
            'options' => $this->options($request, $evento),
            'dateTime' => [
                'start' => $this->dateTimeParts(
                    CarbonImmutable::instance($evento->inicio_em)
                        ->setTimezone($this->timezone($request)),
                ),
                'end' => $this->dateTimeParts(
                    CarbonImmutable::instance($evento->fim_em)
                        ->setTimezone($this->timezone($request)),
                ),
            ],
        ]);
    }

    public function update(
        EventRequest $request,
        Event $evento,
    ): RedirectResponse {
        $data = $request->validated();
        $userParticipants = array_map('intval', $data['participantes_usuarios'] ?? []);
        $citizenParticipants = array_map('intval', $data['participantes_cidadaos'] ?? []);
        unset($data['participantes_usuarios'], $data['participantes_cidadaos']);

        DB::transaction(function () use (
            $request,
            $evento,
            $data,
            $userParticipants,
            $citizenParticipants,
        ): void {
            $evento->update($this->normalizeDates($data, $request));
            $evento->participantesUsuarios()->sync($userParticipants);
            $evento->participantesCidadaos()->sync($citizenParticipants);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Evento atualizado.',
        ]);

        return to_route('events.show', $evento);
    }

    public function destroy(Event $evento): RedirectResponse
    {
        $this->authorize('delete', $evento);
        $evento->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Evento excluído.',
        ]);

        return to_route('events.index');
    }

    /** @return array<string, mixed> */
    private function options(Request $request, ?Event $event = null): array
    {
        return [
            'types' => $this->enumOptions(EventType::cases()),
            'statuses' => $this->enumOptions(EventStatus::cases()),
            'durations' => $this->enumOptions(EventDuration::cases()),
            'members' => User::query()
                ->where('gabinete_id', app(OfficeContext::class)->officeIdFor($request->user()))
                ->where('role', '!=', UserRole::Root)
                ->where('is_active', true)
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get(),
            'citizens' => $event?->participantesCidadaos
                ->map(fn (Citizen $citizen): array => [
                    'id' => $citizen->id,
                    'nome' => $citizen->nome,
                ])
                ->values() ?? collect(),
        ];
    }

    /**
     * @param  array<int, EventType|EventStatus|EventDuration>  $cases
     * @return Collection<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): Collection
    {
        return collect($cases)->map(
            fn (EventType|EventStatus|EventDuration $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
        );
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDates(array $data, Request $request): array
    {
        $timezone = $this->timezone($request);
        $data['inicio_em'] = CarbonImmutable::parse(
            (string) $data['inicio_em'],
            $timezone,
        )->utc();
        $data['fim_em'] = CarbonImmutable::parse(
            (string) $data['fim_em'],
            $timezone,
        )->utc();

        return $data;
    }

    /** @return array{date: string, time: string} */
    private function dateTimeParts(CarbonImmutable $date): array
    {
        return [
            'date' => $date->format('Y-m-d'),
            'time' => $date->format('H:i'),
        ];
    }

    private function timezone(Request $request): string
    {
        return app(OfficeContext::class)->officeFor($request->user())->timezone ?? 'America/Sao_Paulo';
    }

    private function canDelete(Request $request): bool
    {
        return app(OfficeContext::class)->roleFor($request->user())->isAdministrator();
    }
}
