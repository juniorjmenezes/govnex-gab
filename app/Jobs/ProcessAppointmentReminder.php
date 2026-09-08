<?php

namespace App\Jobs;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\GabineteModule;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\AppointmentReminder;
use App\Models\NotificationAttempt;
use App\Services\Appointments\AppointmentReminderChannelManager;
use App\Services\Appointments\FakeWhatsAppAppointmentReminderChannel;
use App\Services\Modules\GabineteModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessAppointmentReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $reminderId) {}

    public function handle(
        AppointmentReminderChannelManager $channels,
        GabineteModuleManager $modules,
    ): void {
        $pending = AppointmentReminder::withoutGlobalScopes()->find($this->reminderId);
        $requiresWhatsApp = in_array($pending?->canal, [
            ReminderChannel::FakeWhatsApp,
            ReminderChannel::WhatsApp,
        ], true);
        if ($pending && (! $modules->isActive($pending->gabinete_id, GabineteModule::Schedule)
            || ($requiresWhatsApp && ! $modules->isActive($pending->gabinete_id, GabineteModule::WhatsApp)))) {
            $pending->forceFill([
                'status' => ReminderStatus::Cancelled,
                'erro' => 'O módulo necessário foi desativado antes do envio.',
                'processado_em' => now(),
            ])->save();

            return;
        }

        $reminder = DB::transaction(function (): ?AppointmentReminder {
            $reminder = AppointmentReminder::withoutGlobalScopes()
                ->with(['compromisso.cidadao'])
                ->lockForUpdate()
                ->find($this->reminderId);

            if (! $reminder
                || ! $reminder->ativo
                || $reminder->status !== ReminderStatus::Pending
                || $reminder->compromisso->status === AppointmentStatus::Cancelled) {
                return null;
            }

            $reminder->update(['status' => ReminderStatus::Processing, 'erro' => null]);

            return $reminder;
        });

        if (! $reminder) {
            return;
        }

        try {
            $results = [];

            foreach ($reminder->destinatarios as $recipient) {
                $recipient = (string) $recipient;
                $key = hash('sha256', $reminder->id.'|'.$reminder->agendado_para->toIso8601String().'|'.$recipient);
                $attempt = NotificationAttempt::withoutGlobalScopes()
                    ->where('idempotency_key', $key)
                    ->first();

                if ($attempt && $attempt->status !== ReminderStatus::Failed) {
                    $results[] = $attempt->status;

                    continue;
                }

                if ($attempt) {
                    $attempt->update([
                        'status' => ReminderStatus::Processing,
                        'tentativas' => $attempt->tentativas + 1,
                        'erro' => null,
                        'iniciado_em' => now(),
                        'concluido_em' => null,
                    ]);
                } else {
                    $attempt = new NotificationAttempt;
                    $attempt->forceFill([
                        'gabinete_id' => $reminder->gabinete_id,
                        'lembrete_id' => $reminder->id,
                        'idempotency_key' => $key,
                        'canal' => $reminder->canal,
                        'destinatario' => $this->recipientLabel($reminder, $recipient),
                        'status' => ReminderStatus::Processing,
                        'payload' => $this->payload($reminder),
                        'iniciado_em' => now(),
                    ])->save();
                }
                try {
                    $result = $channels->for($reminder->canal)->send($reminder, $recipient);
                    $attempt->update([
                        'status' => $result,
                        'concluido_em' => now(),
                    ]);
                    $results[] = $result;
                } catch (Throwable $exception) {
                    $attempt->update([
                        'status' => ReminderStatus::Failed,
                        'erro' => $exception->getMessage(),
                        'concluido_em' => now(),
                    ]);
                    throw $exception;
                }
            }

            $finalStatus = match (true) {
                collect($results)->contains(ReminderStatus::Failed) => ReminderStatus::Failed,
                collect($results)->contains(ReminderStatus::Queued) => ReminderStatus::Queued,
                collect($results)->contains(ReminderStatus::Simulated) => ReminderStatus::Simulated,
                default => ReminderStatus::Sent,
            };
            $reminder->update([
                'status' => $finalStatus,
                'processado_em' => now(),
            ]);
            $this->scheduleNextOccurrence($reminder);
        } catch (Throwable $exception) {
            $reminder->update([
                'status' => ReminderStatus::Pending,
                'erro' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function scheduleNextOccurrence(AppointmentReminder $reminder): void
    {
        $appointment = $reminder->compromisso;

        if ($appointment->recorrencia === AppointmentRecurrence::None) {
            return;
        }

        $occurrenceStart = CarbonImmutable::instance($reminder->agendado_para)
            ->addMinutes($reminder->antecedencia_minutos);
        $nextStart = $this->nextOccurrence($appointment->recorrencia, $occurrenceStart);

        if ($appointment->recorrencia_ate
            && $nextStart->isAfter(CarbonImmutable::instance($appointment->recorrencia_ate)->endOfDay())) {
            return;
        }

        $scheduledFor = $nextStart->subMinutes($reminder->antecedencia_minutos);
        $exists = AppointmentReminder::withoutGlobalScopes()
            ->where('compromisso_id', $appointment->id)
            ->where('canal', $reminder->canal)
            ->where('antecedencia_minutos', $reminder->antecedencia_minutos)
            ->where('agendado_para', $scheduledFor)
            ->exists();

        if ($exists) {
            return;
        }

        $next = new AppointmentReminder;
        $next->forceFill([
            'gabinete_id' => $reminder->gabinete_id,
            'compromisso_id' => $appointment->id,
            'canal' => $reminder->canal,
            'antecedencia_minutos' => $reminder->antecedencia_minutos,
            'destinatarios' => $reminder->destinatarios,
            'ativo' => true,
            'agendado_para' => $scheduledFor,
            'status' => ReminderStatus::Pending,
        ])->save();
    }

    private function nextOccurrence(
        AppointmentRecurrence $recurrence,
        CarbonImmutable $start,
    ): CarbonImmutable {
        return match ($recurrence) {
            AppointmentRecurrence::Daily => $start->addDay(),
            AppointmentRecurrence::Weekly => $start->addWeek(),
            AppointmentRecurrence::Monthly => $start->addMonthNoOverflow(),
            AppointmentRecurrence::None => $start,
        };
    }

    public function failed(Throwable $exception): void
    {
        AppointmentReminder::withoutGlobalScopes()
            ->whereKey($this->reminderId)
            ->update([
                'status' => ReminderStatus::Failed,
                'erro' => $exception->getMessage(),
                'processado_em' => now(),
            ]);
    }

    /** @return array<string, mixed> */
    private function payload(AppointmentReminder $reminder): array
    {
        return [
            'appointment_id' => $reminder->compromisso->id,
            'title' => $reminder->compromisso->titulo,
            'starts_at' => $reminder->compromisso->inicio_em->toIso8601String(),
            'mode' => match ($reminder->canal) {
                ReminderChannel::FakeWhatsApp => 'simulated',
                ReminderChannel::WhatsApp => 'gateway',
                ReminderChannel::Internal => 'database',
            },
        ];
    }

    private function recipientLabel(AppointmentReminder $reminder, string $recipient): string
    {
        if ($reminder->canal === ReminderChannel::FakeWhatsApp) {
            return FakeWhatsAppAppointmentReminderChannel::normalizePhone(
                (string) $reminder->compromisso->cidadao?->whatsapp,
            );
        }

        if ($reminder->canal === ReminderChannel::WhatsApp) {
            return $recipient === 'cidadao' ? 'cidadao' : 'user:'.$recipient;
        }

        return 'user:'.$recipient;
    }
}
