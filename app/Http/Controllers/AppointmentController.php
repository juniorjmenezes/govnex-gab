<?php

namespace App\Http\Controllers;

use App\Actions\Appointments\SaveAppointment;
use App\Actions\Demands\CompleteNextAction;
use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\GabineteModule;
use App\Enums\ReminderChannel;
use App\Enums\UserRole;
use App\Enums\WhatsAppPurpose;
use App\Http\Requests\Appointments\AppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Evento;
use App\Models\NotificationAttempt;
use App\Models\User;
use App\Services\Appointments\AppointmentReminderScheduler;
use App\Services\Modules\GabineteModuleManager;
use App\Services\WhatsApp\WhatsAppEligibilityService;
use App\Services\WhatsApp\WhatsAppEventNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request, GabineteModuleManager $modules): Response
    {
        $this->authorize('viewAny', Appointment::class);
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);

        $view = in_array($request->string('view')->toString(), ['mes', 'semana', 'dia', 'lista'], true)
            ? $request->string('view')->toString()
            : 'mes';
        $date = $this->date($request->string('date')->toString());
        $timezone = $user->gabinete->timezone ?? 'America/Sao_Paulo';
        $eventsEnabled = $modules->isActive($user->gabinete_id, GabineteModule::Events);
        $demandsEnabled = $modules->isActive($user->gabinete_id, GabineteModule::Demands);
        $whatsAppEnabled = $modules->isActive($user->gabinete_id, GabineteModule::WhatsApp);
        [$start, $end] = $this->range($view, $date, $timezone);
        $filters = [
            'view' => $view,
            'date' => $date->toDateString(),
            'responsavel_id' => $request->integer('responsavel_id') ?: null,
            'status' => $request->string('status')->toString(),
            'tipo' => trim($request->string('tipo')->toString()),
        ];

        $appointments = Appointment::query()
            ->where(function (Builder $query) use ($start, $end): void {
                $query->where(function (Builder $overlap) use ($start, $end): void {
                    $overlap->where('inicio_em', '<', $end)
                        ->where('fim_em', '>', $start);
                })->orWhere(function (Builder $recurring) use ($start, $end): void {
                    $recurring->where('recorrencia', '!=', AppointmentRecurrence::None)
                        ->where('inicio_em', '<', $end)
                        ->where(function (Builder $until) use ($start): void {
                            $until->whereNull('recorrencia_ate')
                                ->orWhereDate('recorrencia_ate', '>=', $start);
                        });
                });
            })
            ->when($filters['responsavel_id'], fn (Builder $query) => $query->where('responsavel_id', $filters['responsavel_id']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['tipo'] !== '', fn (Builder $query) => $query->where('tipo', $filters['tipo']))
            ->with([
                'responsavel:id,name',
                'participantes:id,name',
                'cidadao:id,nome,whatsapp,consentimento_contato',
                'demanda:id,protocolo,titulo',
                'criadoPor:id,name',
                'lembretes' => fn ($query) => $query->with('tentativas')->orderBy('agendado_para'),
            ])
            ->orderBy('inicio_em')
            ->limit(500)
            ->get()
            ->flatMap(fn (Appointment $appointment): array => $this->occurrences($appointment, $start, $end, $timezone))
            ->take(500)
            ->values();

        $events = $eventsEnabled ? Evento::query()
            ->where('inicio_em', '<', $end)
            ->where('fim_em', '>', $start)
            ->when($filters['responsavel_id'], fn (Builder $query) => $query->where('responsavel_id', $filters['responsavel_id']))
            ->when($filters['status'] !== '', function (Builder $query) use ($filters): void {
                $statuses = $this->eventStatusesForAgenda((string) $filters['status']);

                $statuses === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('status', $statuses);
            })
            ->when($filters['tipo'] !== '', fn (Builder $query) => $query->where('tipo', $filters['tipo']))
            ->with([
                'responsavel:id,name',
                'criadoPor:id,name',
            ])
            ->orderBy('inicio_em')
            ->limit(500)
            ->get()
            ->flatMap(fn (Evento $event): array => $this->eventOccurrences(
                $event,
                $start,
                $end,
                $timezone,
            )) : collect();

        $agendaItems = $appointments
            ->concat($events)
            ->sortBy('starts_at')
            ->take(500)
            ->values();

        return Inertia::render('appointments/index', [
            'appointments' => $agendaItems,
            'canDelete' => in_array($user->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true),
            'filters' => $filters,
            'range' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'timezone' => $timezone,
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'whatsappSimulated' => (bool) config('services.whatsapp.simulated', true)
                && $whatsAppEnabled,
            'whatsappRealEnabled' => config('whatsapp.driver') === 'gateway'
                && (bool) config('whatsapp.real_enabled')
                && $whatsAppEnabled,
            'capabilities' => [
                'events' => $eventsEnabled,
                'demands' => $demandsEnabled,
                'whatsapp' => $whatsAppEnabled,
            ],
            'options' => [
                'statuses' => $this->enumOptions(AppointmentStatus::cases()),
                'recurrences' => $this->enumOptions(AppointmentRecurrence::cases()),
                'channels' => $this->enumOptions(ReminderChannel::cases()),
                'members' => User::query()
                    ->where('gabinete_id', $user->gabinete_id)
                    ->where('role', '!=', UserRole::Root)
                    ->where('is_active', true)
                    ->select(['id', 'name'])
                    ->with('whatsappContact')
                    ->orderBy('name')
                    ->get()
                    ->map(function (User $member): array {
                        $evaluation = $member->whatsappContact
                            ? app(WhatsAppEligibilityService::class)->evaluate(
                                $member->whatsappContact,
                                WhatsAppPurpose::AppointmentStaffReminder,
                            )
                            : ['eligible' => false];

                        return ['id' => $member->id, 'name' => $member->name, 'whatsapp_ready' => $evaluation['eligible']];
                    }),
                'citizens' => Cidadao::query()
                    ->select(['id', 'nome', 'whatsapp', 'consentimento_contato'])
                    ->with('whatsappContact')
                    ->orderBy('nome')
                    ->limit(300)
                    ->get()
                    ->map(function (Cidadao $citizen): array {
                        $evaluation = $citizen->whatsappContact
                            ? app(WhatsAppEligibilityService::class)->evaluate(
                                $citizen->whatsappContact,
                                WhatsAppPurpose::AppointmentCitizenReminder,
                            )
                            : ['eligible' => false];

                        return [
                            'id' => $citizen->id,
                            'nome' => $citizen->nome,
                            'whatsapp' => $citizen->whatsapp,
                            'consentimento_contato' => $citizen->consentimento_contato,
                            'whatsapp_ready' => $evaluation['eligible'],
                        ];
                    }),
                'demands' => $demandsEnabled
                    ? Demanda::query()
                        ->select(['id', 'protocolo', 'titulo'])
                        ->latest('aberta_em')
                        ->limit(300)
                        ->get()
                    : [],
            ],
        ]);
    }

    public function store(AppointmentRequest $request, SaveAppointment $action): RedirectResponse
    {
        $this->authorize('create', Appointment::class);
        $data = $this->normalizeDates($request->appointmentData(), $request);
        $participants = array_values(array_map('intval', $request->validated('participantes', [])));
        $conflicts = $this->conflicts($data, null, $participants);
        $appointment = $action->handle(
            null,
            $data,
            $participants,
            $request->validated('lembretes', []),
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => $conflicts > 0 ? 'warning' : 'success',
            'message' => $conflicts > 0
                ? "Compromisso criado com {$conflicts} conflito(s) de horário. Revise a agenda."
                : 'Compromisso criado.',
        ]);

        return to_route('appointments.index', [
            'view' => 'dia',
            'date' => $appointment->inicio_em->toDateString(),
        ]);
    }

    public function update(
        AppointmentRequest $request,
        Appointment $appointment,
        SaveAppointment $action,
        WhatsAppEventNotificationService $whatsApp,
    ): RedirectResponse {
        $this->authorize('update', $appointment);

        if ($this->isPastDay($appointment, $request)) {
            throw ValidationException::withMessages([
                'status' => 'Compromissos de dias anteriores permitem somente alterar a situação.',
            ]);
        }

        $before = [
            'inicio_em' => $appointment->inicio_em->toIso8601String(),
            'fim_em' => $appointment->fim_em->toIso8601String(),
            'local' => $appointment->local,
            'cidadao_id' => $appointment->cidadao_id,
            'responsavel_id' => $appointment->responsavel_id,
        ];
        $data = $this->normalizeDates($request->appointmentData(), $request);
        $participants = array_values(array_map('intval', $request->validated('participantes', [])));
        $conflicts = $this->conflicts($data, $appointment, $participants);
        $updated = $action->handle(
            $appointment,
            $data,
            $participants,
            $request->validated('lembretes', []),
            $request->user(),
        );
        $after = [
            'inicio_em' => $updated->inicio_em->toIso8601String(),
            'fim_em' => $updated->fim_em->toIso8601String(),
            'local' => $updated->local,
            'cidadao_id' => $updated->cidadao_id,
            'responsavel_id' => $updated->responsavel_id,
        ];
        if ($before !== $after) {
            $whatsApp->appointmentChanged($updated, "appointment:{$updated->id}:changed:{$updated->updated_at->timestamp}");
        }

        Inertia::flash('toast', [
            'type' => $conflicts > 0 ? 'warning' : 'success',
            'message' => $conflicts > 0
                ? "Compromisso atualizado com {$conflicts} conflito(s) de horário."
                : 'Compromisso atualizado.',
        ]);

        return back();
    }

    public function updateStatus(
        Request $request,
        Appointment $appointment,
        AppointmentReminderScheduler $scheduler,
        WhatsAppEventNotificationService $whatsApp,
        CompleteNextAction $completeNextAction,
    ): RedirectResponse {
        $this->authorize('update', $appointment);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
        ]);
        $status = AppointmentStatus::from($validated['status']);

        $appointment->update(['status' => $status]);

        if (in_array($status, [AppointmentStatus::Completed, AppointmentStatus::Cancelled], true)) {
            $scheduler->cancel($appointment);
        }
        if ($status === AppointmentStatus::Cancelled) {
            $whatsApp->appointmentCancelled(
                $appointment->refresh(),
                "appointment:{$appointment->id}:cancelled:{$appointment->updated_at->timestamp}",
            );
        }

        // Compromisso vinculado a uma demanda, marcado como realizado agora:
        // conclui a próxima ação pendente correspondente (ver também
        // SaveAppointment, mesmo gatilho para quando o compromisso já nasce
        // concluído).
        if ($status === AppointmentStatus::Completed) {
            $demand = $appointment->demanda;
            if ($demand !== null && $demand->hasNextActionPending()) {
                $completeNextAction->handle($demand, $request->user());
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Situação do compromisso atualizada.',
        ]);

        return back();
    }

    public function cancel(
        Appointment $appointment,
        AppointmentReminderScheduler $scheduler,
        WhatsAppEventNotificationService $whatsApp,
    ): RedirectResponse {
        $this->authorize('cancel', $appointment);
        $appointment->update(['status' => AppointmentStatus::Cancelled]);
        $scheduler->cancel($appointment);
        $whatsApp->appointmentCancelled(
            $appointment->refresh(),
            "appointment:{$appointment->id}:cancelled:{$appointment->updated_at->timestamp}",
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Compromisso cancelado e lembretes pendentes interrompidos.']);

        return back();
    }

    /** @param array<string, mixed> $data
     * @param  list<int>  $participants
     */
    private function conflicts(array $data, ?Appointment $current = null, array $participants = []): int
    {
        if (empty($data['responsavel_id']) && $participants === []) {
            return 0;
        }

        return Appointment::query()
            ->when($current, fn (Builder $query) => $query->whereKeyNot($current->id))
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->where('inicio_em', '<', $data['fim_em'])
            ->where('fim_em', '>', $data['inicio_em'])
            ->where(function (Builder $query) use ($data, $participants): void {
                $query->when(
                    ! empty($data['responsavel_id']),
                    fn (Builder $responsible) => $responsible->where('responsavel_id', $data['responsavel_id']),
                )->when(
                    $participants !== [],
                    fn (Builder $members) => $members->orWhereHas(
                        'participantes',
                        fn (Builder $participant) => $participant->whereIn('users.id', $participants),
                    ),
                );
            })
            ->count();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDates(array $data, AppointmentRequest $request): array
    {
        $timezone = $request->user()->gabinete->timezone ?? 'America/Sao_Paulo';
        $data['inicio_em'] = CarbonImmutable::parse((string) $data['inicio_em'], $timezone)->utc();
        $data['fim_em'] = CarbonImmutable::parse((string) $data['fim_em'], $timezone)->utc();

        return $data;
    }

    private function isPastDay(Appointment $appointment, Request $request): bool
    {
        $timezone = $request->user()->gabinete->timezone ?? 'America/Sao_Paulo';

        return CarbonImmutable::instance($appointment->inicio_em)
            ->setTimezone($timezone)
            ->startOfDay()
            ->lt(CarbonImmutable::now($timezone)->startOfDay());
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            return $value !== '' ? CarbonImmutable::parse($value)->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfDay();
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function range(string $view, CarbonImmutable $date, string $timezone): array
    {
        $local = CarbonImmutable::parse($date->toDateString(), $timezone)->startOfDay();
        [$start, $end] = match ($view) {
            'dia' => [$local, $local->addDay()],
            'semana' => [$local->startOfWeek(), $local->endOfWeek()->addSecond()],
            'lista' => [$local, $local->addDays(60)->endOfDay()],
            default => [$local->startOfMonth()->startOfWeek(), $local->endOfMonth()->endOfWeek()->addSecond()],
        };

        return [$start->utc(), $end->utc()];
    }

    /** @return array<int, array<string, mixed>> */
    private function occurrences(
        Appointment $appointment,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $timezone,
    ): array {
        $seriesStart = CarbonImmutable::instance($appointment->inicio_em);
        $seriesEnd = CarbonImmutable::instance($appointment->fim_em);

        if ($appointment->recorrencia === AppointmentRecurrence::None) {
            return [$this->serialize($appointment, $seriesStart, $seriesEnd, $timezone)];
        }

        $duration = $seriesStart->diffInSeconds($seriesEnd);
        $until = $appointment->recorrencia_ate
            ? CarbonImmutable::instance($appointment->recorrencia_ate)->endOfDay()
            : $rangeEnd;
        $cursor = $seriesStart;
        $items = [];

        while ($cursor->lt($rangeEnd) && $cursor->lte($until) && count($items) < 370) {
            $occurrenceEnd = $cursor->addSeconds($duration);

            if ($occurrenceEnd->gt($rangeStart)) {
                $items[] = $this->serialize($appointment, $cursor, $occurrenceEnd, $timezone);
            }

            $cursor = $this->nextOccurrence($appointment->recorrencia, $cursor, $rangeEnd);
        }

        return $items;
    }

    private function nextOccurrence(
        AppointmentRecurrence $recurrence,
        CarbonImmutable $cursor,
        CarbonImmutable $rangeEnd,
    ): CarbonImmutable {
        return match ($recurrence) {
            AppointmentRecurrence::Daily => $cursor->addDay(),
            AppointmentRecurrence::Weekly => $cursor->addWeek(),
            AppointmentRecurrence::Monthly => $cursor->addMonthNoOverflow(),
            AppointmentRecurrence::None => $rangeEnd,
        };
    }

    /** @return array<string, mixed> */
    private function serialize(
        Appointment $appointment,
        CarbonImmutable $occurrenceStart,
        CarbonImmutable $occurrenceEnd,
        string $timezone,
    ): array {
        return [
            'id' => $appointment->id,
            'source' => 'appointment',
            'occurrence_key' => 'appointment-'.$appointment->id.'-'.$occurrenceStart->format('YmdHis'),
            'title' => $appointment->titulo,
            'description' => $appointment->descricao,
            'starts_at' => $occurrenceStart->toIso8601String(),
            'ends_at' => $occurrenceEnd->toIso8601String(),
            'series_starts_at' => $appointment->inicio_em->toIso8601String(),
            'series_ends_at' => $appointment->fim_em->toIso8601String(),
            'all_day' => $appointment->dia_inteiro,
            'location' => $appointment->local,
            'type' => $appointment->tipo,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'is_past' => CarbonImmutable::instance($appointment->inicio_em)
                ->setTimezone($timezone)
                ->startOfDay()
                ->lt(CarbonImmutable::now($timezone)->startOfDay()),
            'notes' => $appointment->observacoes,
            'recurrence' => $appointment->recorrencia->value,
            'recurrence_until' => $appointment->recorrencia_ate?->toDateString(),
            'responsible' => $appointment->responsavel,
            'participants' => $appointment->participantes->values()->all(),
            'citizen' => $appointment->cidadao,
            'demand' => $appointment->demanda,
            'created_by' => $appointment->criadoPor,
            'reminders' => $appointment->lembretes->map(fn (AppointmentReminder $reminder): array => [
                'id' => $reminder->id,
                'channel' => $reminder->canal->value,
                'channel_label' => $reminder->canal->label(),
                'minutes_before' => $reminder->antecedencia_minutos,
                'recipients' => $reminder->destinatarios,
                'active' => $reminder->ativo,
                'scheduled_for' => $reminder->agendado_para->toIso8601String(),
                'status' => $reminder->status->value,
                'status_label' => $reminder->status->label(),
                'error' => $reminder->erro,
                'attempts' => $reminder->tentativas->map(fn (NotificationAttempt $attempt): array => [
                    'id' => $attempt->id,
                    'recipient' => $attempt->destinatario,
                    'status' => $attempt->status->value,
                    'status_label' => $attempt->status->label(),
                    'attempts' => $attempt->tentativas,
                    'error' => $attempt->erro,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function eventOccurrences(
        Evento $event,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $timezone,
    ): array {
        $eventStart = CarbonImmutable::instance($event->inicio_em);
        $eventEnd = CarbonImmutable::instance($event->fim_em);

        if ($event->duracao === EventDuration::SingleDay) {
            return [$this->serializeEvent(
                $event,
                $eventStart,
                $eventEnd,
                $timezone,
            )];
        }

        $localStart = $eventStart->setTimezone($timezone);
        $localEnd = $eventEnd->setTimezone($timezone);
        $cursor = $localStart->startOfDay();
        $lastDay = $localEnd->startOfDay();
        $items = [];

        while ($cursor->lte($lastDay) && count($items) < 370) {
            $occurrenceStart = $cursor->setTime(
                $localStart->hour,
                $localStart->minute,
                $localStart->second,
            )->utc();
            $occurrenceEnd = $cursor->setTime(
                $localEnd->hour,
                $localEnd->minute,
                $localEnd->second,
            )->utc();

            if ($occurrenceStart->lt($rangeEnd) && $occurrenceEnd->gt($rangeStart)) {
                $items[] = $this->serializeEvent(
                    $event,
                    $occurrenceStart,
                    $occurrenceEnd,
                    $timezone,
                );
            }

            $cursor = $cursor->addDay();
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function serializeEvent(
        Evento $event,
        CarbonImmutable $occurrenceStart,
        CarbonImmutable $occurrenceEnd,
        string $timezone,
    ): array {
        $agendaStatus = match ($event->status) {
            EventStatus::Planned => AppointmentStatus::Scheduled,
            EventStatus::Confirmed, EventStatus::InProgress => AppointmentStatus::Confirmed,
            EventStatus::Completed => AppointmentStatus::Completed,
            EventStatus::Cancelled => AppointmentStatus::Cancelled,
        };

        return [
            'id' => $event->id,
            'source' => 'event',
            'occurrence_key' => 'event-'.$event->id.'-'.$occurrenceStart->format('Ymd'),
            'title' => $event->titulo,
            'description' => $event->descricao,
            'starts_at' => $occurrenceStart->toIso8601String(),
            'ends_at' => $occurrenceEnd->toIso8601String(),
            'series_starts_at' => $event->inicio_em->toIso8601String(),
            'series_ends_at' => $event->fim_em->toIso8601String(),
            'all_day' => false,
            'location' => $event->local,
            'type' => $event->tipo->label(),
            'status' => $agendaStatus->value,
            'status_label' => $event->status->label(),
            'is_past' => $occurrenceStart
                ->setTimezone($timezone)
                ->startOfDay()
                ->lt(CarbonImmutable::now($timezone)->startOfDay()),
            'notes' => $event->observacoes,
            'recurrence' => AppointmentRecurrence::None->value,
            'recurrence_until' => null,
            'responsible' => $event->responsavel,
            'participants' => [],
            'citizen' => null,
            'demand' => null,
            'created_by' => $event->criadoPor,
            'reminders' => [],
        ];
    }

    /** @return list<string> */
    private function eventStatusesForAgenda(string $status): array
    {
        return match ($status) {
            AppointmentStatus::Scheduled->value => [EventStatus::Planned->value],
            AppointmentStatus::Confirmed->value => [
                EventStatus::Confirmed->value,
                EventStatus::InProgress->value,
            ],
            AppointmentStatus::Completed->value => [EventStatus::Completed->value],
            AppointmentStatus::Cancelled->value => [EventStatus::Cancelled->value],
            default => [],
        };
    }

    /**
     * @param  array<int, AppointmentStatus>|array<int, AppointmentRecurrence>|array<int, ReminderChannel>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            fn (AppointmentStatus|AppointmentRecurrence|ReminderChannel $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }

    public function destroy(Appointment $appointment): RedirectResponse
    {
        $this->authorize('delete', $appointment);
        $appointment->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Compromisso excluído.']);

        return to_route('appointments.index');
    }
}
