<?php

namespace Tests\Feature;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\GabineteModule;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Jobs\ProcessAppointmentReminder;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Evento;
use App\Models\Gabinete;
use App\Models\NotificationAttempt;
use App\Models\User;
use App\Services\Appointments\AppointmentReminderChannelManager;
use App\Services\Modules\GabineteModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_are_automatically_included_in_the_agenda_without_duplication(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $otherOffice = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $start = CarbonImmutable::parse('2026-08-17 14:00', 'America/Sao_Paulo')->utc();
        $event = Evento::factory()->forGabinete($office, $user, $user)->create([
            'titulo' => 'Assembleia comunitária',
            'tipo' => EventType::Assembly,
            'status' => EventStatus::InProgress,
            'inicio_em' => $start,
            'fim_em' => $start->addHours(2),
        ]);
        Evento::factory()->forGabinete($otherOffice)->create([
            'inicio_em' => $start,
            'fim_em' => $start->addHour(),
        ]);

        $this->actingAs($user)
            ->get(route('appointments.index', [
                'view' => 'dia',
                'date' => '2026-08-17',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 1)
                ->where('appointments.0.id', $event->id)
                ->where('appointments.0.source', 'event')
                ->where('appointments.0.occurrence_key', 'event-'.$event->id.'-20260817')
                ->where('appointments.0.title', 'Assembleia comunitária')
                ->where('appointments.0.type', 'Assembleia')
                ->where('appointments.0.status', AppointmentStatus::Confirmed->value)
                ->where('appointments.0.status_label', 'Em andamento')
                ->where('appointments.0.starts_at', '2026-08-17T17:00:00+00:00'));

        $this->assertDatabaseCount('compromissos', 0);
    }

    public function test_multiple_day_events_are_shown_once_per_day_with_the_daily_schedule(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $start = CarbonImmutable::parse('2026-08-15 09:00', 'America/Sao_Paulo')->utc();
        $end = CarbonImmutable::parse('2026-08-17 17:00', 'America/Sao_Paulo')->utc();
        $event = Evento::factory()->forGabinete($office, $user, $user)->create([
            'duracao' => EventDuration::MultipleDays,
            'inicio_em' => $start,
            'fim_em' => $end,
        ]);

        $this->actingAs($user)
            ->get(route('appointments.index', [
                'view' => 'lista',
                'date' => '2026-08-15',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 3)
                ->where('appointments.0.occurrence_key', 'event-'.$event->id.'-20260815')
                ->where('appointments.0.starts_at', '2026-08-15T12:00:00+00:00')
                ->where('appointments.0.ends_at', '2026-08-15T20:00:00+00:00')
                ->where('appointments.1.occurrence_key', 'event-'.$event->id.'-20260816')
                ->where('appointments.1.starts_at', '2026-08-16T12:00:00+00:00')
                ->where('appointments.1.ends_at', '2026-08-16T20:00:00+00:00')
                ->where('appointments.2.occurrence_key', 'event-'.$event->id.'-20260817')
                ->where('appointments.2.starts_at', '2026-08-17T12:00:00+00:00')
                ->where('appointments.2.ends_at', '2026-08-17T20:00:00+00:00'));
    }

    public function test_new_appointment_opens_in_its_own_page_with_form_options(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();

        $this->actingAs($user)
            ->get(route('appointments.create', ['data' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('appointments/create')
                ->where('defaults.date', now()->addDay()->toDateString())
                ->has('options.statuses')
                ->has('options.members'));
    }

    public function test_agenda_is_visible_and_strictly_isolated_by_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $foreign = Appointment::factory()->forGabinete($otherOffice)->create([
            'inicio_em' => now()->startOfMonth()->addDays(3),
            'fim_em' => now()->startOfMonth()->addDays(3)->addHour(),
        ]);

        $this->actingAs($user)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('appointments/index')
                ->has('appointments', 0));

        $this->actingAs($user)
            ->put(route('appointments.update', $foreign), $this->payload())
            ->assertNotFound();
    }

    public function test_appointment_creation_persists_relations_recurrence_and_reminders(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 08:00', 'America/Sao_Paulo'));
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $participant = User::factory()->advisor()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create([
            'whatsapp' => '85999998888',
            'consentimento_contato' => true,
        ]);
        $demand = Demanda::factory()->forGabinete($office)->create();

        $payload = $this->payload([
            'participantes' => [$participant->id],
            'cidadao_id' => $citizen->id,
            'demanda_id' => $demand->id,
            'recorrencia' => AppointmentRecurrence::Weekly->value,
            'recorrencia_ate' => '2026-09-30',
            'lembretes' => [
                [
                    'canal' => ReminderChannel::Internal->value,
                    'antecedencia_minutos' => 30,
                    'destinatarios' => [$participant->id],
                    'ativo' => true,
                ],
                [
                    'canal' => ReminderChannel::FakeWhatsApp->value,
                    'antecedencia_minutos' => 60,
                    'destinatarios' => ['cidadao'],
                    'ativo' => true,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('appointments.store'), $payload)
            ->assertRedirect();

        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame($office->id, $appointment->gabinete_id);
        $this->assertSame(AppointmentRecurrence::Weekly, $appointment->recorrencia);
        $this->assertSame([$participant->id], $appointment->participantes()->pluck('users.id')->all());
        $this->assertCount(2, $appointment->lembretes);
        $this->assertSame(
            '2026-08-10 11:30:00',
            $appointment->lembretes()->where('canal', ReminderChannel::Internal)->sole()->agendado_para->format('Y-m-d H:i:s'),
        );

        $this->actingAs($user)
            ->get(route('appointments.index', ['view' => 'dia', 'date' => '2026-08-17']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 1)
                ->where('appointments.0.id', $appointment->id)
                ->where('appointments.0.starts_at', '2026-08-17T12:00:00+00:00'));
    }

    public function test_scheduling_an_appointment_for_a_demand_does_not_complete_its_pending_next_action(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 08:00', 'America/Sao_Paulo'));
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office)->create();
        $demand->forceFill([
            'proxima_acao_descricao' => 'Visitar Central de Regulação',
            'proxima_acao_data' => now()->addDay(),
        ])->save();

        // Só agendar o compromisso (status padrão "agendado") não significa
        // que a visita já aconteceu — a ação continua pendente.
        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload([
                'demanda_id' => $demand->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull($demand->refresh()->proxima_acao_concluida_em);
    }

    public function test_completing_a_linked_appointment_completes_the_pending_next_action(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 08:00', 'America/Sao_Paulo'));
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office)->create();
        $demand->forceFill([
            'proxima_acao_descricao' => 'Visitar Central de Regulação',
            'proxima_acao_data' => now()->addDay(),
        ])->save();

        $this->actingAs($user)->post(route('appointments.store'), $this->payload([
            'demanda_id' => $demand->id,
        ]));
        $appointment = Appointment::withoutGlobalScopes()->sole();

        $this->actingAs($user)
            ->patch(route('appointments.status', $appointment), [
                'status' => AppointmentStatus::Completed->value,
            ])
            ->assertRedirect();

        $demand->refresh();
        $this->assertNotNull($demand->proxima_acao_concluida_em);
        $this->assertDatabaseHas('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'proxima_acao_concluida',
        ]);
    }

    public function test_appointment_already_created_as_completed_completes_the_pending_next_action(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 08:00', 'America/Sao_Paulo'));
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office)->create();
        $demand->forceFill([
            'proxima_acao_descricao' => 'Visitar Central de Regulação',
            'proxima_acao_data' => now()->subDay(),
        ])->save();

        // Registro retroativo: a visita já aconteceu, só está sendo lançada
        // na agenda agora, direto como "concluído".
        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload([
                'demanda_id' => $demand->id,
                'status' => AppointmentStatus::Completed->value,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNotNull($demand->refresh()->proxima_acao_concluida_em);
    }

    public function test_linking_an_appointment_to_a_demand_without_a_pending_next_action_does_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 08:00', 'America/Sao_Paulo'));
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $demand = Demanda::factory()->forGabinete($office)->create();

        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload([
                'demanda_id' => $demand->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('demanda_eventos', [
            'demanda_id' => $demand->id,
            'tipo' => 'proxima_acao_concluida',
        ]);
    }

    public function test_appointments_cannot_be_created_on_previous_days_but_today_is_allowed(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 15:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload([
                'inicio_em' => '2026-08-09T09:00',
                'fim_em' => '2026-08-09T10:00',
            ]))
            ->assertSessionHasErrors('inicio_em');

        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload([
                'inicio_em' => '2026-08-10T09:00',
                'fim_em' => '2026-08-10T10:00',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('compromissos', 1);
    }

    public function test_past_appointments_only_allow_status_changes(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 15:00', 'America/Sao_Paulo'));
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
            'titulo' => 'Título original',
            'inicio_em' => CarbonImmutable::parse('2026-08-09 09:00', 'America/Sao_Paulo')->utc(),
            'fim_em' => CarbonImmutable::parse('2026-08-09 10:00', 'America/Sao_Paulo')->utc(),
        ]);

        $this->actingAs($user)
            ->put(route('appointments.update', $appointment), $this->payload([
                'titulo' => 'Título alterado',
                'inicio_em' => '2026-08-09T09:00',
                'fim_em' => '2026-08-09T10:00',
            ]))
            ->assertSessionHasErrors('inicio_em');

        $this->assertSame('Título original', $appointment->refresh()->titulo);

        $this->actingAs($user)
            ->patch(route('appointments.status', $appointment), [
                'status' => AppointmentStatus::Completed->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
        $this->assertSame('Título original', $appointment->titulo);
    }

    public function test_foreign_participants_and_links_are_rejected(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $foreignUser = User::factory()->advisor()->forGabinete($otherOffice)->create();
        $foreignCitizen = Cidadao::factory()->forGabinete($otherOffice)->create();

        $this->actingAs($user)
            ->post(route('appointments.store'), $this->payload([
                'responsavel_id' => $foreignUser->id,
                'participantes' => [$foreignUser->id],
                'cidadao_id' => $foreignCitizen->id,
            ]))
            ->assertSessionHasErrors(['responsavel_id', 'participantes.0', 'cidadao_id']);

        $this->assertDatabaseCount('compromissos', 0);
    }

    public function test_rescheduling_cancels_old_reminders_and_creates_recalculated_ones(): void
    {
        $office = Gabinete::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $start = CarbonImmutable::now($office->timezone)->addMonth()->startOfDay()->setTime(15, 0);
        $end = $start->addHour();
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
            'responsavel_id' => $user->id,
        ]);
        $old = $appointment->lembretes()->create([
            'canal' => ReminderChannel::Internal,
            'antecedencia_minutos' => 30,
            'destinatarios' => [$user->id],
            'ativo' => true,
            'agendado_para' => now()->addDay(),
            'status' => ReminderStatus::Pending,
        ]);

        $this->actingAs($user)
            ->put(route('appointments.update', $appointment), $this->payload([
                'responsavel_id' => $user->id,
                'inicio_em' => $start->format('Y-m-d\TH:i'),
                'fim_em' => $end->format('Y-m-d\TH:i'),
                'lembretes' => [[
                    'canal' => ReminderChannel::Internal->value,
                    'antecedencia_minutos' => 60,
                    'destinatarios' => [$user->id],
                    'ativo' => true,
                ]],
            ]))
            ->assertRedirect();

        $this->assertSame(ReminderStatus::Cancelled, $old->refresh()->status);
        $new = $appointment->lembretes()->whereKeyNot($old->id)->sole();
        $this->assertSame(ReminderStatus::Pending, $new->status);
        $this->assertSame(
            $start->subHour()->utc()->format('Y-m-d H:i:s'),
            $new->agendado_para->format('Y-m-d H:i:s'),
        );
    }

    public function test_cancelling_an_appointment_cancels_pending_reminders(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
        ]);
        $reminder = $appointment->lembretes()->create([
            'canal' => ReminderChannel::Internal,
            'antecedencia_minutos' => 30,
            'destinatarios' => [$user->id],
            'ativo' => true,
            'agendado_para' => now()->addHour(),
            'status' => ReminderStatus::Pending,
        ]);

        $this->actingAs($user)
            ->patch(route('appointments.cancel', $appointment))
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertSame(ReminderStatus::Cancelled, $reminder->refresh()->status);
        $this->assertFalse($reminder->ativo);
    }

    public function test_internal_reminder_is_processed_once_and_creates_a_database_notification(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
            'recorrencia' => AppointmentRecurrence::Weekly,
            'recorrencia_ate' => now()->addMonth(),
        ]);
        $reminder = $this->reminder($appointment, ReminderChannel::Internal, [$user->id]);

        $job = new ProcessAppointmentReminder($reminder->id);
        $job->handle(
            app(AppointmentReminderChannelManager::class),
            app(GabineteModuleManager::class),
        );
        $job->handle(
            app(AppointmentReminderChannelManager::class),
            app(GabineteModuleManager::class),
        );

        $this->assertSame(ReminderStatus::Sent, $reminder->refresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, NotificationAttempt::withoutGlobalScopes()->count());
        $this->assertSame(2, $appointment->lembretes()->count());
        $this->assertSame(ReminderStatus::Pending, $appointment->lembretes()->whereKeyNot($reminder->id)->sole()->status);
    }

    public function test_fake_whatsapp_is_audited_as_simulated_without_external_requests(): void
    {
        Http::fake();
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create([
            'whatsapp' => '(85) 99999-8888',
            'consentimento_contato' => true,
        ]);
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
            'cidadao_id' => $citizen->id,
        ]);
        $reminder = $this->reminder($appointment, ReminderChannel::FakeWhatsApp, ['cidadao']);

        (new ProcessAppointmentReminder($reminder->id))
            ->handle(
                app(AppointmentReminderChannelManager::class),
                app(GabineteModuleManager::class),
            );

        $attempt = NotificationAttempt::withoutGlobalScopes()->sole();
        $this->assertSame(ReminderStatus::Simulated, $reminder->refresh()->status);
        $this->assertSame(ReminderStatus::Simulated, $attempt->status);
        $this->assertSame('+5585999998888', $attempt->destinatario);
        $this->assertSame('simulated', $attempt->payload['mode']);
        Http::assertNothingSent();
    }

    public function test_pending_reminder_is_cancelled_when_schedule_is_disabled(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();
        $user = User::factory()->councilor()->forGabinete($office)->create();
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $user->id,
        ]);
        $reminder = $this->reminder($appointment, ReminderChannel::Internal, [$user->id]);
        $modules = app(GabineteModuleManager::class);
        $modules->sync($office, [GabineteModule::Relationship->value], $admin);

        (new ProcessAppointmentReminder($reminder->id))->handle(
            app(AppointmentReminderChannelManager::class),
            $modules,
        );

        $this->assertSame(ReminderStatus::Cancelled, $reminder->refresh()->status);
        $this->assertDatabaseCount('notificacao_tentativas', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notifications_can_only_be_marked_read_by_their_owner(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $owner = User::factory()->councilor()->forGabinete($office)->create();
        $outsider = User::factory()->councilor()->forGabinete($otherOffice)->create();
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'criado_por_id' => $owner->id,
        ]);
        $reminder = $this->reminder($appointment, ReminderChannel::Internal, [$owner->id]);
        (new ProcessAppointmentReminder($reminder->id))
            ->handle(
                app(AppointmentReminderChannelManager::class),
                app(GabineteModuleManager::class),
            );
        $notification = $owner->notifications()->sole();

        $this->actingAs($outsider)
            ->patch(route('notifications.read', $notification->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->patch(route('notifications.read', $notification->id))
            ->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'titulo' => 'Reunião comunitária',
            'descricao' => 'Alinhamento com lideranças locais.',
            'inicio_em' => '2026-08-10T09:00',
            'fim_em' => '2026-08-10T10:00',
            'dia_inteiro' => false,
            'local' => 'Gabinete',
            'responsavel_id' => null,
            'participantes' => [],
            'cidadao_id' => null,
            'demanda_id' => null,
            'tipo' => 'reuniao',
            'status' => AppointmentStatus::Scheduled->value,
            'observacoes' => null,
            'recorrencia' => AppointmentRecurrence::None->value,
            'recorrencia_ate' => null,
            'lembretes' => [],
            ...$overrides,
        ];
    }

    /** @param array<int, int|string> $recipients */
    private function reminder(
        Appointment $appointment,
        ReminderChannel $channel,
        array $recipients,
    ): AppointmentReminder {
        return $appointment->lembretes()->create([
            'canal' => $channel,
            'antecedencia_minutos' => 30,
            'destinatarios' => $recipients,
            'ativo' => true,
            'agendado_para' => now()->subMinute(),
            'status' => ReminderStatus::Pending,
        ]);
    }
}
