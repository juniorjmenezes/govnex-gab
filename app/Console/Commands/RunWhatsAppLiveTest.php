<?php

namespace App\Console\Commands;

use App\Actions\Appointments\SaveAppointment;
use App\Actions\Demands\CreateDemand;
use App\Actions\Demands\CreateDemandUpdate;
use App\Actions\Demands\TransitionDemandStatus;
use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use App\Jobs\ProcessAppointmentReminder;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Categoria;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\Appointments\AppointmentReminderScheduler;
use App\Services\Demands\DemandNotificationService;
use App\Services\WhatsApp\WhatsAppDeadlineDigestService;
use App\Services\WhatsApp\WhatsAppEventNotificationService;
use App\Services\WhatsApp\WhatsAppOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RunWhatsAppLiveTest extends Command
{
    private const EXPECTED_MESSAGES = 40;

    /** @var array<string, int> */
    private const EXPECTED_BY_PURPOSE = [
        'DEMANDA_ATRIBUIDA' => 3,
        'DEMANDA_STATUS_ALTERADO' => 6,
        'DEMANDA_OBSERVACAO_ADICIONADA' => 2,
        'DEMANDAS_PRAZO_RESUMO' => 2,
        'AGENDA_LEMBRETE_EQUIPE' => 6,
        'AGENDA_LEMBRETE_CIDADAO' => 3,
        'AGENDA_ALTERADA' => 9,
        'AGENDA_CANCELADA' => 9,
    ];

    /** @var list<string> */
    private const EXECUTION_STAGES = [
        'demands',
        'digest',
        'agenda-reminders',
        'agenda-changed',
        'agenda-cancelled',
    ];

    protected $signature = 'govnexgab:whatsapp-live-test
        {--office=Fortaleza : Nome ou slug exato do gabinete}
        {--team-contact=* : IDs dos dois contatos de equipe}
        {--citizen-contact=* : IDs dos três contatos de cidadãos}
        {--run-id= : UUID do ensaio para execução ou retomada}
        {--stage=all : all, demands, digest, agenda-reminders, agenda-changed, agenda-cancelled ou report}
        {--expected-messages= : Confirma o total esperado de 40 mensagens}
        {--wait-seconds=180 : Tempo máximo de espera por lote}
        {--confirm-live : Autoriza criação de dados e envios reais em modo LIVE}';

    protected $description = 'Executa, com dry-run padrão, o ensaio ampliado dos oito templates WhatsApp em modo LIVE';

    /** @var array<string, mixed> */
    private array $manifest = [];

    private string $manifestPath = '';

    private int $waitSeconds = 180;

    public function handle(): int
    {
        try {
            $stage = $this->stage();
            $runId = $this->runId();
            $this->waitSeconds = max(0, min(900, (int) $this->option('wait-seconds')));
            $existing = $this->loadManifest($runId);
            $office = $this->office((string) $this->option('office'), $existing);
            [$teamContacts, $citizenContacts] = $this->contacts($office, $existing);
            $this->preflight($office, $teamContacts, $citizenContacts, $existing);
            $this->showDryRun($runId, $office, $teamContacts, $citizenContacts);

            if (! $this->option('confirm-live')) {
                $this->components->info('SIMULAÇÃO: nenhum dado foi criado e nenhuma mensagem foi enviada.');
                $this->line('Para executar, reutilize o run-id acima, informe os cinco contatos e use --confirm-live --expected-messages=40.');

                return self::SUCCESS;
            }
            if ((int) $this->option('expected-messages') !== self::EXPECTED_MESSAGES) {
                throw new RuntimeException('A execução exige --expected-messages=40.');
            }

            $lock = Cache::lock('govnexgab:whatsapp-live-test:'.$office->id, 1800);
            if (! $lock->get()) {
                throw new RuntimeException('Já existe um ensaio WhatsApp em execução para este gabinete.');
            }

            try {
                $this->manifestPath = 'whatsapp-live-tests/'.$runId.'.json';
                $this->manifest = $existing ?? $this->newManifest($runId, $office, $teamContacts, $citizenContacts);
                $this->saveManifest();

                if ($stage === 'report') {
                    return $this->report(final: true);
                }

                foreach ($stage === 'all' ? self::EXECUTION_STAGES : [$stage] as $currentStage) {
                    $this->executeStage($currentStage, $office, $teamContacts, $citizenContacts);
                }

                $allStagesCompleted = $this->allExecutionStagesCompleted();
                $alreadyCompleted = ($this->manifest['status'] ?? null) === 'COMPLETED';
                $this->manifest['status'] = $allStagesCompleted
                    ? ($alreadyCompleted ? 'COMPLETED' : 'AWAITING_CALLBACKS')
                    : 'PARTIAL';
                $this->manifest['updated_at'] = now()->toIso8601String();
                $this->saveManifest();

                if (! $allStagesCompleted) {
                    $this->components->info('Lote concluído. Os demais lotes permanecem pendentes para este run-id.');

                    return self::SUCCESS;
                }

                return $this->report(final: $alreadyCompleted);
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            if ($this->manifest !== []) {
                $this->manifest['status'] = 'FAILED';
                $this->manifest['last_error'] = mb_substr($exception->getMessage(), 0, 500);
                $this->manifest['updated_at'] = now()->toIso8601String();
                $this->saveManifest();
            }
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @param  Collection<int, WhatsAppContact>  $citizenContacts
     */
    private function executeStage(
        string $stage,
        Gabinete $office,
        Collection $teamContacts,
        Collection $citizenContacts,
    ): void {
        if (($this->manifest['stages'][$stage]['status'] ?? null) === 'COMPLETED') {
            $this->components->info("{$stage}: já concluído; nenhuma mensagem foi repetida.");

            return;
        }

        $this->assertNoNegativeStatuses();
        $this->manifest['stages'][$stage] = [
            'status' => 'RUNNING',
            'started_at' => now()->toIso8601String(),
        ];
        $this->saveManifest();
        $this->components->info("Iniciando lote {$stage}.");

        try {
            $ids = match ($stage) {
                'demands' => $this->runDemands($office, $teamContacts, $citizenContacts),
                'digest' => $this->runDigest($teamContacts),
                'agenda-reminders' => $this->runAgendaReminders($office, $teamContacts, $citizenContacts),
                'agenda-changed' => $this->runAgendaChanged($teamContacts),
                'agenda-cancelled' => $this->runAgendaCancelled(),
                default => throw new RuntimeException('Lote desconhecido: '.$stage.'.'),
            };
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $this->manifest['notification_ids'] = array_values(array_unique(array_merge(
                array_map('intval', (array) ($this->manifest['notification_ids'] ?? [])),
                $ids,
            )));
            $this->manifest['stages'][$stage]['notification_ids'] = $ids;
            $this->manifest['updated_at'] = now()->toIso8601String();
            $this->saveManifest();
            $this->awaitSubmitted($ids, $stage);
            $this->manifest['stages'][$stage] = [
                'status' => 'COMPLETED',
                'notification_ids' => $ids,
                'completed_at' => now()->toIso8601String(),
            ];
            $this->manifest['updated_at'] = now()->toIso8601String();
            $this->saveManifest();
            $this->components->info("{$stage}: ".count($ids).' mensagens aceitas pela outbox/gateway.');
        } catch (Throwable $exception) {
            $this->manifest['stages'][$stage]['status'] = 'FAILED';
            $this->manifest['stages'][$stage]['error'] = mb_substr($exception->getMessage(), 0, 500);
            $this->manifest['stages'][$stage]['failed_at'] = now()->toIso8601String();
            $this->saveManifest();
            throw $exception;
        }
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @param  Collection<int, WhatsAppContact>  $citizenContacts
     * @return list<int>
     */
    private function runDemands(Gabinete $office, Collection $teamContacts, Collection $citizenContacts): array
    {
        $users = $teamContacts->map->user->values();
        $citizens = $citizenContacts->map->citizen->values();
        $category = Categoria::withoutGlobalScopes()
            ->where('gabinete_id', $office->id)
            ->where('ativo', true)
            ->orderBy('id')
            ->firstOrFail();
        $titles = [
            'Acompanhamento de iluminação pública',
            'Atualização de atendimento comunitário',
            'Solicitação de manutenção urbana',
        ];
        $deadlines = [now()->subHour(), now()->addHours(6), now()->addHours(18)];
        $responsibleIndexes = [0, 1, 0];
        $demandIds = array_values(array_map('intval', (array) ($this->manifest['records']['demands'] ?? [])));

        for ($index = count($demandIds); $index < 3; $index++) {
            $citizen = $citizens[$index];
            $responsible = $users[$responsibleIndexes[$index]];
            $demand = app(CreateDemand::class)->handle([
                'cidadao_id' => $citizen->id,
                'categoria_id' => $category->id,
                'bairro_id' => $citizen->bairro_id,
                'responsavel_id' => $responsible->id,
                'titulo' => $titles[$index],
                'descricao' => 'Solicitação registrada para acompanhamento e retorno pelos canais disponíveis.',
                'prioridade' => DemandPriority::Normal,
                'origem' => DemandOrigin::InPerson,
                'prazo' => $deadlines[$index],
            ], $users[0]);
            $demandIds[] = $demand->id;
            $this->manifest['records']['demands'] = $demandIds;
            $this->saveManifest();
        }

        $assigned = $this->notificationsForOrigins(
            Demanda::class,
            $demandIds,
            [WhatsAppPurpose::DemandAssigned],
        );
        $this->awaitSubmitted($assigned, 'demands-assigned', 3);

        foreach ($demandIds as $demandId) {
            $demand = Demanda::withoutGlobalScopes()->findOrFail($demandId);
            if ($demand->status === DemandStatus::New) {
                app(TransitionDemandStatus::class)->handle($demand, DemandStatus::InProgress, $users[0]);
            } elseif ($demand->status !== DemandStatus::InProgress) {
                throw new RuntimeException("A demanda {$demand->protocolo} foi alterada fora do ensaio.");
            }
        }
        $status = $this->notificationsForOrigins(
            Demanda::class,
            $demandIds,
            [WhatsAppPurpose::DemandStatusChanged],
        );
        $this->awaitSubmitted($status, 'demands-status', 6);

        $observationIds = array_map('intval', (array) ($this->manifest['records']['observations'] ?? []));
        foreach ([0, 1] as $index) {
            $demand = Demanda::withoutGlobalScopes()->findOrFail($demandIds[$index]);
            $author = $users[$index === 0 ? 1 : 0];
            $text = 'Registro complementar para continuidade do acompanhamento.';
            $existing = $demand->eventos()
                ->where('tipo', 'atualizacao')
                ->where('usuario_id', $author->id)
                ->where('descricao', $text)
                ->first();
            $event = $existing ?? app(CreateDemandUpdate::class)->handle($demand, $author, $text);
            if ($existing) {
                app(DemandNotificationService::class)->updateAdded($demand->refresh(), $author);
            }
            $observationIds[] = $event->id;
            $this->manifest['records']['observations'] = array_values(array_unique($observationIds));
            $this->saveManifest();
        }
        $observations = $this->notificationsForOrigins(
            Demanda::class,
            array_slice($demandIds, 0, 2),
            [WhatsAppPurpose::DemandObservationAdded],
        );
        $this->awaitSubmitted($observations, 'demands-observations', 2);

        $ids = array_values(array_unique(array_merge($assigned, $status, $observations)));
        if (count($ids) !== 11) {
            throw new RuntimeException('O lote de demandas não produziu as 11 notificações previstas.');
        }

        return $ids;
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @return list<int>
     */
    private function runDigest(Collection $teamContacts): array
    {
        $ids = [];
        $now = CarbonImmutable::now('UTC');
        foreach ($teamContacts as $contact) {
            [$overdue, $soon, $referrals] = app(WhatsAppDeadlineDigestService::class)
                ->counts($contact->user, $now);
            if (($overdue + $soon + $referrals) === 0) {
                throw new RuntimeException('Não existem prazos aplicáveis para '.$contact->user->name.'.');
            }
            $notification = app(WhatsAppOutboxService::class)->enqueue(
                $contact,
                WhatsAppPurpose::DemandDeadlineDigest,
                "whatsapp-live-test:{$this->manifest['run_id']}:digest:{$contact->user->id}",
                [$contact->user->name, (string) $overdue, (string) $soon, (string) $referrals],
            );
            if (! $notification) {
                throw new RuntimeException('O resumo de prazos foi bloqueado para '.$contact->user->name.'.');
            }
            $ids[] = $notification->id;
        }
        if (count(array_unique($ids)) !== 2) {
            throw new RuntimeException('O lote de resumo não produziu as duas notificações previstas.');
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @param  Collection<int, WhatsAppContact>  $citizenContacts
     * @return list<int>
     */
    private function runAgendaReminders(
        Gabinete $office,
        Collection $teamContacts,
        Collection $citizenContacts,
    ): array {
        $users = $teamContacts->map->user->values();
        $citizens = $citizenContacts->map->citizen->values();
        $appointmentIds = array_values(array_map('intval', (array) ($this->manifest['records']['appointments'] ?? [])));
        $reminderIds = array_map('intval', (array) ($this->manifest['records']['reminders'] ?? []));
        $titles = ['Atendimento de acompanhamento', 'Reunião de alinhamento', 'Retorno de solicitação'];
        $hours = [9, 11, 14];
        $timezone = $office->timezone ?: 'America/Fortaleza';

        for ($index = count($appointmentIds); $index < 3; $index++) {
            $start = CarbonImmutable::now($timezone)->addDay()->startOfDay()->setTime($hours[$index], 0)->utc();
            $responsible = $users[$index % 2];
            $participant = $users[($index + 1) % 2];
            $minutes = max(1, (int) CarbonImmutable::now('UTC')->diffInMinutes($start, false));
            $appointment = app(SaveAppointment::class)->handle(
                null,
                [
                    'responsavel_id' => $responsible->id,
                    'cidadao_id' => $citizens[$index]->id,
                    'demanda_id' => (int) $this->manifest['records']['demands'][$index],
                    'titulo' => $titles[$index],
                    'descricao' => 'Encontro reservado para atualização e encaminhamento da solicitação.',
                    'inicio_em' => $start,
                    'fim_em' => $start->addMinutes(45),
                    'dia_inteiro' => false,
                    'local' => 'Gabinete de Fortaleza - Sala de atendimento',
                    'tipo' => 'atendimento',
                    'status' => AppointmentStatus::Scheduled,
                    'recorrencia' => AppointmentRecurrence::None,
                    'recorrencia_ate' => null,
                    'observacoes' => null,
                ],
                [$participant->id],
                [[
                    'canal' => ReminderChannel::WhatsApp,
                    'antecedencia_minutos' => $minutes,
                    'destinatarios' => [(string) $users[0]->id, (string) $users[1]->id, 'cidadao'],
                    'ativo' => true,
                ]],
                $users[0],
            );
            $reminder = $appointment->lembretes()->firstOrFail();
            $reminder->forceFill(['agendado_para' => now()->subSecond()])->save();
            $appointmentIds[] = $appointment->id;
            $reminderIds[] = $reminder->id;
            $this->manifest['records']['appointments'] = $appointmentIds;
            $this->manifest['records']['reminders'] = $reminderIds;
            $this->saveManifest();
        }

        foreach ($reminderIds as $reminderId) {
            $reminder = AppointmentReminder::withoutGlobalScopes()->findOrFail($reminderId);
            if ($reminder->status === ReminderStatus::Pending) {
                ProcessAppointmentReminder::dispatch($reminder->id);
            }
        }

        $ids = $this->awaitOriginNotifications(
            Appointment::class,
            $appointmentIds,
            [WhatsAppPurpose::AppointmentStaffReminder, WhatsAppPurpose::AppointmentCitizenReminder],
            9,
            'agenda-reminders',
        );
        $this->assertPurposeCount($ids, WhatsAppPurpose::AppointmentStaffReminder, 6);
        $this->assertPurposeCount($ids, WhatsAppPurpose::AppointmentCitizenReminder, 3);

        return $ids;
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @return list<int>
     */
    private function runAgendaChanged(Collection $teamContacts): array
    {
        $users = $teamContacts->map->user->values();
        $appointmentIds = array_values(array_map('intval', (array) ($this->manifest['records']['appointments'] ?? [])));
        if (count($appointmentIds) !== 3) {
            throw new RuntimeException('Os três compromissos precisam existir antes da alteração.');
        }

        foreach ($appointmentIds as $appointmentId) {
            $existing = $this->notificationsForOrigins(
                Appointment::class,
                [$appointmentId],
                [WhatsAppPurpose::AppointmentChanged],
            );
            if ($existing !== []) {
                continue;
            }
            $appointment = Appointment::withoutGlobalScopes()->with('participantes')->findOrFail($appointmentId);
            if ($appointment->status !== AppointmentStatus::Scheduled) {
                throw new RuntimeException('Um compromisso não está disponível para alteração.');
            }
            $updated = app(SaveAppointment::class)->handle(
                $appointment,
                [
                    'responsavel_id' => $appointment->responsavel_id,
                    'cidadao_id' => $appointment->cidadao_id,
                    'demanda_id' => $appointment->demanda_id,
                    'titulo' => $appointment->titulo,
                    'descricao' => $appointment->descricao,
                    'inicio_em' => CarbonImmutable::instance($appointment->inicio_em)->addMinutes(30),
                    'fim_em' => CarbonImmutable::instance($appointment->fim_em)->addMinutes(30),
                    'dia_inteiro' => $appointment->dia_inteiro,
                    'local' => 'Gabinete de Fortaleza - Sala de reuniões',
                    'tipo' => $appointment->tipo,
                    'status' => $appointment->status,
                    'recorrencia' => $appointment->recorrencia,
                    'recorrencia_ate' => $appointment->recorrencia_ate,
                    'observacoes' => $appointment->observacoes,
                ],
                array_values($appointment->participantes->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                [],
                $users[0],
            );
            app(WhatsAppEventNotificationService::class)->appointmentChanged(
                $updated,
                "appointment:{$updated->id}:changed:{$updated->updated_at->timestamp}",
            );
        }

        return $this->awaitOriginNotifications(
            Appointment::class,
            $appointmentIds,
            [WhatsAppPurpose::AppointmentChanged],
            9,
            'agenda-changed',
        );
    }

    /** @return list<int> */
    private function runAgendaCancelled(): array
    {
        $appointmentIds = array_values(array_map('intval', (array) ($this->manifest['records']['appointments'] ?? [])));
        if (count($appointmentIds) !== 3) {
            throw new RuntimeException('Os três compromissos precisam existir antes do cancelamento.');
        }

        foreach ($appointmentIds as $appointmentId) {
            $appointment = Appointment::withoutGlobalScopes()->findOrFail($appointmentId);
            $existing = $this->notificationsForOrigins(
                Appointment::class,
                [$appointmentId],
                [WhatsAppPurpose::AppointmentCancelled],
            );
            if ($existing !== []) {
                continue;
            }
            if ($appointment->status !== AppointmentStatus::Cancelled) {
                $appointment->forceFill(['status' => AppointmentStatus::Cancelled])->save();
                app(AppointmentReminderScheduler::class)->cancel($appointment);
            }
            app(WhatsAppEventNotificationService::class)->appointmentCancelled(
                $appointment->refresh(),
                "appointment:{$appointment->id}:cancelled:{$appointment->updated_at->timestamp}",
            );
        }

        return $this->awaitOriginNotifications(
            Appointment::class,
            $appointmentIds,
            [WhatsAppPurpose::AppointmentCancelled],
            9,
            'agenda-cancelled',
        );
    }

    private function report(bool $final): int
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($this->manifest['notification_ids'] ?? []))));
        if (count($ids) !== self::EXPECTED_MESSAGES) {
            throw new RuntimeException('O manifesto possui '.count($ids).' notificações; eram esperadas 40.');
        }
        if ($final) {
            $this->awaitMinimumStatus($ids, WhatsAppNotificationStatus::Sent, 'relatório final');
        } else {
            $this->awaitMinimumStatus($ids, WhatsAppNotificationStatus::Submitted, 'relatório preliminar');
        }

        $notifications = WhatsAppNotification::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        $purposeCounts = $notifications->countBy(fn (WhatsAppNotification $item): string => $item->finalidade->value);
        foreach (self::EXPECTED_BY_PURPOSE as $purpose => $expected) {
            if ((int) $purposeCounts->get($purpose, 0) !== $expected) {
                throw new RuntimeException("{$purpose} possui {$purposeCounts->get($purpose, 0)} mensagens; eram esperadas {$expected}.");
            }
        }

        $statusCounts = $notifications->countBy(fn (WhatsAppNotification $item): string => $item->status->value);
        $this->table(
            ['Finalidade', 'Quantidade'],
            collect(self::EXPECTED_BY_PURPOSE)->map(fn (int $count, string $purpose): array => [$purpose, $count])->values()->all(),
        );
        $this->table(
            ['Estado', 'Quantidade'],
            $statusCounts->map(fn (int $count, string $status): array => [$status, $count])->values()->all(),
        );

        $this->manifest['status'] = $final ? 'COMPLETED' : 'AWAITING_CALLBACKS';
        $this->manifest['report'] = [
            'generated_at' => now()->toIso8601String(),
            'final' => $final,
            'purpose_counts' => $purposeCounts->all(),
            'status_counts' => $statusCounts->all(),
            'notifications' => $notifications->map(fn (WhatsAppNotification $item): array => [
                'id' => $item->id,
                'client_request_id' => $item->client_request_id,
                'purpose' => $item->finalidade->value,
                'contact_id' => $item->whatsapp_contato_id,
                'phone_last_four' => $item->telefone_final,
                'status' => $item->status->value,
                'attempts' => $item->tentativas,
                'submitted_at' => $item->submetido_em?->toIso8601String(),
                'sent_at' => $item->enviado_em?->toIso8601String(),
                'delivered_at' => $item->entregue_em?->toIso8601String(),
                'read_at' => $item->lido_em?->toIso8601String(),
            ])->all(),
        ];
        $this->manifest['updated_at'] = now()->toIso8601String();
        $this->saveManifest();
        $this->components->info($final
            ? 'Ensaio concluído: as 40 mensagens alcançaram ao menos SENT.'
            : 'Os cinco lotes foram aceitos. Execute --stage=report após a consolidação dos callbacks.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @param  Collection<int, WhatsAppContact>  $citizenContacts
     * @param  array<string, mixed>|null  $existing
     */
    private function preflight(
        Gabinete $office,
        Collection $teamContacts,
        Collection $citizenContacts,
        ?array $existing,
    ): void {
        if (config('whatsapp.driver') !== 'gateway' || ! config('whatsapp.real_enabled')) {
            throw new RuntimeException('O driver real do Gateway WhatsApp não está habilitado.');
        }
        $configuration = WhatsAppConfiguration::withoutGlobalScopes()
            ->where('gabinete_id', $office->id)
            ->first();
        if (! $configuration || $configuration->modo !== WhatsAppMode::Live) {
            throw new RuntimeException('O gabinete precisa estar em modo LIVE.');
        }
        $purposes = array_map(
            fn (WhatsAppPurpose $purpose): string => $purpose->value,
            WhatsAppPurpose::operationalUtilityCases(),
        );
        $missingPurposes = array_diff($purposes, (array) $configuration->finalidades_habilitadas);
        if ($missingPurposes !== []) {
            throw new RuntimeException('Finalidades desabilitadas: '.implode(', ', $missingPurposes).'.');
        }
        $readyTemplates = WhatsAppTemplatePurpose::query()
            ->whereIn('finalidade', $purposes)
            ->get()
            ->filter->isReady();
        if ($readyTemplates->count() !== count($purposes)) {
            throw new RuntimeException('Os oito templates operacionais precisam estar aprovados, sincronizados e ativos.');
        }
        if ($teamContacts->count() !== 2 || $citizenContacts->count() !== 3) {
            throw new RuntimeException('O ensaio exige exatamente dois contatos de equipe e três cidadãos.');
        }
        foreach ($teamContacts->concat($citizenContacts) as $contact) {
            if ($contact->gabinete_id !== $office->id || ! $contact->isEligible()) {
                throw new RuntimeException('Um dos contatos selecionados não está elegível no gabinete informado.');
            }
            if ($contact->usuario_id && (! $contact->user || ! $contact->user->is_active || $contact->user->trashed())) {
                throw new RuntimeException('Um integrante da equipe está inativo ou removido.');
            }
            if ($contact->cidadao_id && (! $contact->citizen || $contact->citizen->trashed())) {
                throw new RuntimeException('Um cidadão está removido.');
            }
        }
        $allowedPending = array_map('intval', (array) ($existing['notification_ids'] ?? []));
        $pending = WhatsAppNotification::withoutGlobalScopes()
            ->where('gabinete_id', $office->id)
            ->whereIn('status', [
                WhatsAppNotificationStatus::Pending->value,
                WhatsAppNotificationStatus::Processing->value,
                WhatsAppNotificationStatus::Reconciling->value,
            ])
            ->when($allowedPending !== [], fn ($query) => $query->whereNotIn('id', $allowedPending))
            ->count();
        if ($pending > 0) {
            throw new RuntimeException('A outbox do gabinete possui mensagens pendentes fora deste ensaio.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array{Collection<int, WhatsAppContact>, Collection<int, WhatsAppContact>}
     */
    private function contacts(Gabinete $office, ?array $existing): array
    {
        $teamIds = $existing['contacts']['team_ids'] ?? $this->integerOptions('team-contact');
        $citizenIds = $existing['contacts']['citizen_ids'] ?? $this->integerOptions('citizen-contact');
        if ($this->option('confirm-live') && $existing === null && (count($teamIds) !== 2 || count($citizenIds) !== 3)) {
            throw new RuntimeException('Na primeira execução confirmada, informe dois --team-contact e três --citizen-contact.');
        }
        if ($teamIds === [] && $citizenIds === []) {
            $eligible = WhatsAppContact::withoutGlobalScopes()
                ->with(['user', 'citizen'])
                ->where('gabinete_id', $office->id)
                ->get()
                ->filter->isEligible();
            $teamIds = $eligible->whereNotNull('usuario_id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $citizenIds = $eligible->whereNotNull('cidadao_id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $team = WhatsAppContact::withoutGlobalScopes()
            ->with(['user', 'citizen'])
            ->where('gabinete_id', $office->id)
            ->whereIn('id', $teamIds)
            ->whereNotNull('usuario_id')
            ->orderBy('id')
            ->get();
        $citizens = WhatsAppContact::withoutGlobalScopes()
            ->with(['user', 'citizen'])
            ->where('gabinete_id', $office->id)
            ->whereIn('id', $citizenIds)
            ->whereNotNull('cidadao_id')
            ->orderBy('id')
            ->get();

        return [$team, $citizens];
    }

    /** @param array<string, mixed>|null $existing */
    private function office(string $value, ?array $existing): Gabinete
    {
        if ($existing !== null) {
            return Gabinete::withoutGlobalScopes()->findOrFail((int) $existing['office']['id']);
        }
        $normalized = Str::slug(trim($value));
        $offices = Gabinete::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('slug', $normalized)->orWhere('nome', trim($value)))
            ->get();
        if ($offices->count() !== 1) {
            throw new RuntimeException('O gabinete informado não foi encontrado de forma única.');
        }

        return $offices->first();
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @param  Collection<int, WhatsAppContact>  $citizenContacts
     */
    private function showDryRun(
        string $runId,
        Gabinete $office,
        Collection $teamContacts,
        Collection $citizenContacts,
    ): void {
        $this->line('Run ID: '.$runId);
        $this->line('Gabinete: '.$office->nome.' (LIVE)');
        $this->table(['Contato', 'Tipo', 'Nome', 'Final'], $teamContacts->concat($citizenContacts)
            ->map(fn (WhatsAppContact $contact): array => [
                $contact->id,
                $contact->usuario_id ? 'EQUIPE' : 'CIDADÃO',
                $contact->usuario_id ? $contact->user?->name : $contact->citizen?->nome,
                $contact->telefone_final,
            ])->all());
        $this->table(
            ['Finalidade', 'Previstas'],
            collect(self::EXPECTED_BY_PURPOSE)->map(fn (int $count, string $purpose): array => [$purpose, $count])->values()->all(),
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $originIds
     * @param  list<WhatsAppPurpose>  $purposes
     * @return list<int>
     */
    private function notificationsForOrigins(string $modelClass, array $originIds, array $purposes): array
    {
        $morph = (new $modelClass)->getMorphClass();

        return array_values(WhatsAppNotification::withoutGlobalScopes()
            ->where('origem_type', $morph)
            ->whereIn('origem_id', $originIds)
            ->whereIn('finalidade', array_map(fn (WhatsAppPurpose $purpose): string => $purpose->value, $purposes))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $originIds
     * @param  list<WhatsAppPurpose>  $purposes
     * @return list<int>
     */
    private function awaitOriginNotifications(
        string $modelClass,
        array $originIds,
        array $purposes,
        int $expected,
        string $label,
    ): array {
        $deadline = microtime(true) + $this->waitSeconds;
        do {
            $ids = $this->notificationsForOrigins($modelClass, $originIds, $purposes);
            if (count($ids) === $expected) {
                $this->awaitSubmitted($ids, $label, $expected);

                return $ids;
            }
            if (count($ids) > $expected) {
                throw new RuntimeException("{$label} produziu mensagens acima do previsto.");
            }
            if ($this->waitSeconds === 0 || microtime(true) >= $deadline) {
                break;
            }
            sleep(2);
        } while (true);

        throw new RuntimeException("{$label} não produziu {$expected} mensagens no prazo esperado.");
    }

    /** @param list<int> $ids */
    private function awaitSubmitted(array $ids, string $label, ?int $expected = null): void
    {
        if ($expected !== null && count(array_unique($ids)) !== $expected) {
            throw new RuntimeException("{$label} produziu ".count(array_unique($ids))." mensagens; eram esperadas {$expected}.");
        }
        $this->awaitMinimumStatus($ids, WhatsAppNotificationStatus::Submitted, $label);
    }

    /** @param list<int> $ids */
    private function awaitMinimumStatus(array $ids, WhatsAppNotificationStatus $minimum, string $label): void
    {
        if ($ids === []) {
            throw new RuntimeException("{$label} não possui notificações para validar.");
        }
        $deadline = microtime(true) + $this->waitSeconds;
        do {
            $notifications = WhatsAppNotification::withoutGlobalScopes()->whereIn('id', $ids)->get();
            $negative = $notifications->filter(fn (WhatsAppNotification $item): bool => in_array($item->status, [
                WhatsAppNotificationStatus::Failed,
                WhatsAppNotificationStatus::Expired,
                WhatsAppNotificationStatus::Cancelled,
                WhatsAppNotificationStatus::Suppressed,
            ], true));
            if ($negative->isNotEmpty()) {
                $details = $negative->map(fn (WhatsAppNotification $item): string => "#{$item->id} {$item->finalidade->value} {$item->status->value}")->implode(', ');
                throw new RuntimeException("{$label} contém falha definitiva: {$details}.");
            }
            if ($notifications->count() === count(array_unique($ids))
                && $notifications->every(fn (WhatsAppNotification $item): bool => $item->status->rank() >= $minimum->rank())) {
                return;
            }
            if ($this->waitSeconds === 0 || microtime(true) >= $deadline) {
                break;
            }
            sleep(2);
        } while (true);

        throw new RuntimeException("{$label} não alcançou {$minimum->value} no prazo configurado.");
    }

    private function assertNoNegativeStatuses(): void
    {
        $ids = array_map('intval', (array) ($this->manifest['notification_ids'] ?? []));
        if ($ids === []) {
            return;
        }
        $negative = WhatsAppNotification::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->whereIn('status', [
                WhatsAppNotificationStatus::Failed->value,
                WhatsAppNotificationStatus::Expired->value,
                WhatsAppNotificationStatus::Cancelled->value,
                WhatsAppNotificationStatus::Suppressed->value,
            ])
            ->exists();
        if ($negative) {
            throw new RuntimeException('Um lote anterior possui falha definitiva; novos disparos foram bloqueados.');
        }
    }

    /** @param list<int> $ids */
    private function assertPurposeCount(array $ids, WhatsAppPurpose $purpose, int $expected): void
    {
        $actual = WhatsAppNotification::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('finalidade', $purpose->value)
            ->count();
        if ($actual !== $expected) {
            throw new RuntimeException("{$purpose->value} produziu {$actual} mensagens; eram esperadas {$expected}.");
        }
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $teamContacts
     * @param  Collection<int, WhatsAppContact>  $citizenContacts
     * @return array<string, mixed>
     */
    private function newManifest(
        string $runId,
        Gabinete $office,
        Collection $teamContacts,
        Collection $citizenContacts,
    ): array {
        return [
            'version' => 1,
            'run_id' => $runId,
            'status' => 'PREPARED',
            'expected_messages' => self::EXPECTED_MESSAGES,
            'office' => ['id' => $office->id, 'name' => $office->nome],
            'contacts' => [
                'team_ids' => $teamContacts->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'citizen_ids' => $citizenContacts->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'items' => $teamContacts->concat($citizenContacts)->map(fn (WhatsAppContact $contact): array => [
                    'id' => $contact->id,
                    'type' => $contact->usuario_id ? 'TEAM' : 'CITIZEN',
                    'name' => $contact->usuario_id ? $contact->user?->name : $contact->citizen?->nome,
                    'phone_last_four' => $contact->telefone_final,
                ])->values()->all(),
            ],
            'records' => ['demands' => [], 'observations' => [], 'appointments' => [], 'reminders' => []],
            'notification_ids' => [],
            'stages' => [],
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadManifest(string $runId): ?array
    {
        $path = 'whatsapp-live-tests/'.$runId.'.json';
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }
        $data = json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['run_id'] ?? null) !== $runId || (int) ($data['version'] ?? 0) !== 1) {
            throw new RuntimeException('O manifesto existente é inválido.');
        }
        $this->manifestPath = $path;

        return $data;
    }

    private function saveManifest(): void
    {
        if ($this->manifest === [] || $this->manifestPath === '') {
            return;
        }
        $temporary = $this->manifestPath.'.tmp';
        $json = json_encode($this->manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
        if (! Storage::disk('local')->put($temporary, $json)) {
            throw new RuntimeException('Não foi possível gravar o manifesto privado.');
        }
        Storage::disk('local')->delete($this->manifestPath);
        if (! Storage::disk('local')->move($temporary, $this->manifestPath)) {
            throw new RuntimeException('Não foi possível consolidar o manifesto privado.');
        }
        @chmod(Storage::disk('local')->path($this->manifestPath), 0600);
    }

    private function runId(): string
    {
        $value = trim((string) $this->option('run-id'));
        if ($value === '') {
            return (string) Str::uuid();
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new RuntimeException('O run-id precisa ser um UUID válido.');
        }

        return strtolower($value);
    }

    private function stage(): string
    {
        $stage = strtolower(trim((string) $this->option('stage')));
        if (! in_array($stage, [...self::EXECUTION_STAGES, 'all', 'report'], true)) {
            throw new RuntimeException('Lote inválido: '.$stage.'.');
        }

        return $stage;
    }

    /** @return list<int> */
    private function integerOptions(string $name): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value): int => (int) $value,
            (array) $this->option($name),
        ), fn (int $value): bool => $value > 0)));
    }

    private function allExecutionStagesCompleted(): bool
    {
        return collect(self::EXECUTION_STAGES)
            ->every(fn (string $stage): bool => ($this->manifest['stages'][$stage]['status'] ?? null) === 'COMPLETED');
    }
}
