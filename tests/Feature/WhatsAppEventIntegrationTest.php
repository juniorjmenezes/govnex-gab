<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Enums\DemandStatus;
use App\Enums\OfficeModule;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Jobs\ProcessAppointmentReminder;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Candidate;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\Election;
use App\Models\ElectoralPoll;
use App\Models\ElectoralPollResult;
use App\Models\FavoriteCandidate;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Services\Appointments\AppointmentReminderChannelManager;
use App\Services\Demands\DemandNotificationService;
use App\Services\Modules\OfficeModuleManager;
use App\Services\Politics\PoliticalPollNotificationService;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppContactService;
use App\Services\WhatsApp\WhatsAppDeadlineDigestService;
use App\Services\WhatsApp\WhatsAppEventNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ProvisionsEntidadeWhatsApp;
use Tests\TestCase;

class WhatsAppEventIntegrationTest extends TestCase
{
    use ProvisionsEntidadeWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set([
            'whatsapp.driver' => 'gateway',
            'whatsapp.real_enabled' => true,
            'whatsapp.gateway_url' => 'https://whatsapp.example.test',
            'whatsapp.client_code' => 'GABINETE',
            'whatsapp.request_secret' => str_repeat('r', 32),
            'whatsapp.callback_secret' => str_repeat('c', 32),
            'whatsapp.phone_hash_secret' => str_repeat('h', 32),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_demand_events_use_only_eligible_recipients_and_are_idempotent(): void
    {
        [$office, $responsible, $citizen] = $this->eligibleOffice([
            WhatsAppPurpose::DemandAssigned,
            WhatsAppPurpose::DemandStatusChanged,
            WhatsAppPurpose::DemandObservationAdded,
        ]);
        $author = User::factory()->forGabinete($office)->create();
        $demand = Demand::factory()->forGabinete($office, $citizen, creator: $author)->create([
            'responsavel_id' => $responsible->id,
            'prazo' => now()->addDays(3),
        ]);
        $notifications = app(DemandNotificationService::class);

        $notifications->assigned($demand);
        $notifications->assigned($demand);
        $demand->forceFill(['status' => DemandStatus::InProgress])->save();
        $notifications->statusChanged($demand->refresh(), DemandStatus::InProgress);
        $notifications->updateAdded($demand->refresh(), $author);

        $this->assertDatabaseCount('whatsapp_notificacoes', 4);
        $this->assertDatabaseHas('whatsapp_notificacoes', [
            'gabinete_id' => $office->id,
            'finalidade' => WhatsAppPurpose::DemandAssigned->value,
        ]);
        $this->assertSame(2, WhatsAppNotification::query()
            ->where('finalidade', WhatsAppPurpose::DemandStatusChanged->value)
            ->count());
        $this->assertSame(1, WhatsAppNotification::query()
            ->where('finalidade', WhatsAppPurpose::DemandObservationAdded->value)
            ->count());
    }

    /**
     * `BelongsToOffice::creating` grava `gabinete_id` a partir do CONTEXTO
     * atual, e módulos/conexões ativos também são resolvidos por ele. Um
     * destinatário elegível de OUTRO gabinete (ex.: aviso de pesquisa
     * eleitoral a favoritos de várias câmaras, disparado durante uma
     * requisição de um gabinete diferente) tinha a avaliação de elegibilidade
     * e a notificação resolvidas para o gabinete de quem disparou o evento,
     * não o do próprio destinatário — a notificação não saía, silenciosamente.
     * `safeEnqueue()` agora troca o contexto para o gabinete do contato antes
     * de enfileirar.
     */
    public function test_enqueueing_for_a_recipient_in_another_office_keeps_the_notification_in_the_recipients_office(): void
    {
        [$otherOffice, $recipient] = $this->eligibleTeamContact([WhatsAppPurpose::DemandAssigned]);
        $actorOffice = Office::factory()->create();
        $actor = User::factory()->forGabinete($actorOffice)->create();

        $this->actingAs($actor);
        app(WhatsAppEventNotificationService::class)->enqueueForUser(
            $recipient,
            WhatsAppPurpose::DemandAssigned,
            'cross-office-test',
            [$recipient->name, 'PROTO-1', 'Sem prazo definido'],
        );

        $notification = WhatsAppNotification::withoutGlobalScopes()->sole();
        $this->assertSame($otherOffice->id, $notification->gabinete_id);
        $this->assertNotSame($actorOffice->id, $notification->gabinete_id);
    }

    public function test_disabling_demands_stops_new_internal_and_whatsapp_notifications(): void
    {
        [$office, $responsible, $citizen] = $this->eligibleOffice([
            WhatsAppPurpose::DemandAssigned,
            WhatsAppPurpose::DemandStatusChanged,
        ]);
        $admin = User::factory()->root()->create();
        $author = User::factory()->forGabinete($office)->create();
        $demand = Demand::factory()->forGabinete($office, $citizen, creator: $author)->create([
            'responsavel_id' => $responsible->id,
        ]);
        app(OfficeModuleManager::class)->sync($office, [
            OfficeModule::Relationship->value,
            OfficeModule::Schedule->value,
            OfficeModule::WhatsApp->value,
        ], $admin);

        $notifications = app(DemandNotificationService::class);
        $notifications->assigned($demand);
        $notifications->statusChanged($demand, DemandStatus::InProgress);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
    }

    public function test_appointment_changes_cancellation_and_reminders_cover_team_and_citizen(): void
    {
        [$office, $responsible, $citizen] = $this->eligibleOffice([
            WhatsAppPurpose::AppointmentChanged,
            WhatsAppPurpose::AppointmentCancelled,
            WhatsAppPurpose::AppointmentStaffReminder,
            WhatsAppPurpose::AppointmentCitizenReminder,
        ]);
        $participant = User::factory()->forGabinete($office)->create();
        app(WhatsAppContactService::class)->declareForUser($participant, '(88) 97777-4321', $participant);
        $appointment = Appointment::factory()->forGabinete($office)->create([
            'responsavel_id' => $responsible->id,
            'cidadao_id' => $citizen->id,
            'criado_por_id' => $responsible->id,
            'inicio_em' => now()->addDay(),
            'fim_em' => now()->addDay()->addHour(),
        ]);
        $appointment->participantes()->attach($participant->id);
        $events = app(WhatsAppEventNotificationService::class);

        $events->appointmentChanged($appointment, 'appointment:1:changed:test');
        $events->appointmentChanged($appointment, 'appointment:1:changed:test');
        $events->appointmentCancelled($appointment, 'appointment:1:cancelled:test');

        $this->assertDatabaseCount('whatsapp_notificacoes', 6);

        $reminder = new AppointmentReminder;
        $reminder->forceFill([
            'gabinete_id' => $office->id,
            'compromisso_id' => $appointment->id,
            'canal' => ReminderChannel::WhatsApp,
            'antecedencia_minutos' => 60,
            'destinatarios' => [(string) $responsible->id, 'cidadao'],
            'ativo' => true,
            'agendado_para' => now(),
            'status' => ReminderStatus::Pending,
        ])->save();

        (new ProcessAppointmentReminder($reminder->id))->handle(
            app(AppointmentReminderChannelManager::class),
            app(OfficeModuleManager::class),
        );

        $this->assertSame(ReminderStatus::Queued, $reminder->fresh()->status);
        $this->assertDatabaseCount('notificacao_tentativas', 2);
        $this->assertDatabaseCount('whatsapp_notificacoes', 8);
    }

    public function test_daily_deadline_digest_is_aggregated_once_per_recipient_and_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 11:00:00', 'UTC'));
        [$office, $responsible, $citizen] = $this->eligibleOffice([
            WhatsAppPurpose::DemandDeadlineDigest,
        ]);
        $office->forceFill(['timezone' => 'America/Fortaleza'])->save();

        Demand::factory()->forGabinete($office, $citizen, creator: $responsible)->create([
            'responsavel_id' => $responsible->id,
            'prazo' => now()->subHour(),
        ]);
        Demand::factory()->forGabinete($office, $citizen, creator: $responsible)->create([
            'responsavel_id' => $responsible->id,
            'prazo' => now()->addHours(6),
        ]);

        $digest = app(WhatsAppDeadlineDigestService::class);
        $this->assertSame(1, $digest->dispatchDue());
        $this->assertSame(1, $digest->dispatchDue());

        $this->assertDatabaseCount('whatsapp_notificacoes', 1);
        $notification = WhatsAppNotification::query()->firstOrFail();
        $this->assertSame(WhatsAppPurpose::DemandDeadlineDigest, $notification->finalidade);
        $this->assertSame($office->id, $notification->gabinete_id);
    }

    /**
     * O membro pausado no Time continua com `whatsapp_contatos` elegível e
     * `users.is_active = true` — só `gabinete_membros.ativo` muda. Sem o
     * gate, o dígest diário continuava avisando quem foi pausado.
     */
    public function test_daily_deadline_digest_skips_a_team_member_paused_in_the_team_screen(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 11:00:00', 'UTC'));
        [$office, $responsible, $citizen] = $this->eligibleOffice([
            WhatsAppPurpose::DemandDeadlineDigest,
        ]);
        $office->forceFill(['timezone' => 'America/Fortaleza'])->save();
        OfficeMember::query()->updateOrCreate(
            ['gabinete_id' => $office->id, 'usuario_id' => $responsible->id],
            ['papel' => AccessRole::Operator, 'ativo' => false, 'ingressou_em' => now(), 'desativado_em' => now()],
        );

        Demand::factory()->forGabinete($office, $citizen, creator: $responsible)->create([
            'responsavel_id' => $responsible->id,
            'prazo' => now()->subHour(),
        ]);

        $digest = app(WhatsAppDeadlineDigestService::class);
        $this->assertSame(0, $digest->dispatchDue());
        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
    }

    /**
     * Mesmo gate, mas no caminho de evento único (`enqueueForUser`), usado
     * por atribuição/mudança de status de demanda, lembretes de compromisso
     * e pesquisa eleitoral publicada.
     */
    public function test_assignment_notification_skips_a_team_member_paused_in_the_team_screen(): void
    {
        [$office, $responsible] = $this->eligibleTeamContact([WhatsAppPurpose::DemandAssigned]);
        OfficeMember::query()->updateOrCreate(
            ['gabinete_id' => $office->id, 'usuario_id' => $responsible->id],
            ['papel' => AccessRole::Operator, 'ativo' => false, 'ingressou_em' => now(), 'desativado_em' => now()],
        );
        $author = User::factory()->forGabinete($office)->create();
        $citizen = Citizen::factory()->forGabinete($office)->create();
        $demand = Demand::factory()->forGabinete($office, $citizen, creator: $author)->create([
            'responsavel_id' => $responsible->id,
        ]);

        app(DemandNotificationService::class)->assigned($demand);

        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
    }

    public function test_published_poll_keeps_internal_notification_without_enqueuing_marketing_whatsapp(): void
    {
        [$office, $councilor] = $this->eligibleTeamContact([], councilor: true);
        $otherOffice = Office::factory()->create();
        $otherCouncilor = User::factory()->administrator()->forGabinete($otherOffice)->create();
        app(WhatsAppContactService::class)->declareForUser($otherCouncilor, '(85) 96666-7654', $otherCouncilor);
        $election = Election::query()->firstOrFail();
        $candidate = Candidate::query()->create([
            'eleicao_id' => $election->id,
            'sq_candidato' => 'WHATSAPP-TEST-1',
            'abrangencia' => 'municipal',
            'uf' => 'CE',
            'cargo' => 'Vereador',
            'nome' => 'Candidato de teste',
            'nome_urna' => 'Teste',
        ]);
        $favorite = new FavoriteCandidate;
        $favorite->forceFill([
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $candidate->id,
            'escolhido_por_id' => $councilor->id,
        ])->save();
        $poll = ElectoralPoll::query()->create([
            'eleicao_id' => $election->id,
            'external_id' => (string) Str::uuid(),
            'external_election_id' => (string) Str::uuid(),
            'ano' => $election->ano,
            'uf' => 'CE',
            'municipio' => 'Cruz',
            'cargo' => 'Vereador',
            'turno' => 1,
            'instituto' => 'Instituto Teste',
            'publicada_em' => now(),
            'abrangencia' => 'Municipal',
            'fonte_url' => 'https://example.test/pesquisa',
        ]);
        ElectoralPollResult::query()->create([
            'pesquisa_eleitoral_id' => $poll->id,
            'external_candidate_id' => (string) Str::uuid(),
            'candidato_politico_id' => $candidate->id,
            'candidato_nome' => $candidate->nome_urna,
            'percentual' => 10.5,
        ]);

        $service = app(PoliticalPollNotificationService::class);
        $this->assertSame(1, $service->notifyFavorites($poll));
        $this->assertSame(0, $service->notifyFavorites($poll));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('whatsapp_notificacoes', 0);
    }

    /** @param list<WhatsAppPurpose> $purposes
     * @return array{Office,User,Citizen}
     */
    private function eligibleOffice(array $purposes): array
    {
        [$office, $user] = $this->eligibleTeamContact($purposes);
        $citizen = Citizen::factory()->forGabinete($office)->create();
        app(WhatsAppContactService::class)->declareForCitizen($citizen, '(88) 98888-5678', $user);

        return [$office, $user, $citizen];
    }

    /** @param list<WhatsAppPurpose> $purposes
     * @return array{Office,User}
     */
    private function eligibleTeamContact(array $purposes, bool $councilor = false): array
    {
        $office = Office::factory()->create();
        $factory = User::factory()->forGabinete($office);
        $user = ($councilor ? $factory->administrator() : $factory)->create();
        app(WhatsAppContactService::class)->declareForUser($user, '(88) 99999-1234', $user);
        $this->entidadeWhatsAppConnection($office);
        app(WhatsAppConfigurationService::class)->update(
            $office,
            WhatsAppMode::Live,
            '08:00',
            array_map(fn (WhatsAppPurpose $purpose): string => $purpose->value, $purposes),
        );
        foreach ($purposes as $purpose) {
            $this->readyEntidadeWhatsAppTemplate($office, $purpose);
        }

        return [$office, $user];
    }
}
