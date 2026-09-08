<?php

namespace App\Services\Appointments;

use App\Contracts\Appointments\AppointmentReminderChannel;
use App\Enums\ReminderStatus;
use App\Enums\WhatsAppPurpose;
use App\Models\AppointmentReminder;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppOutboxService;
use Carbon\CarbonImmutable;

final class WhatsAppAppointmentReminderChannel implements AppointmentReminderChannel
{
    public function __construct(private readonly WhatsAppOutboxService $outbox) {}

    public function send(AppointmentReminder $reminder, string $recipient): ReminderStatus
    {
        $appointment = $reminder->compromisso->loadMissing([
            'gabinete',
            'cidadao',
            'responsavel',
            'participantes',
        ]);
        $contact = $recipient === 'cidadao'
            ? WhatsAppContact::withoutGlobalScopes()
                ->where('gabinete_id', $reminder->gabinete_id)
                ->where('cidadao_id', $appointment->cidadao_id)
                ->first()
            : WhatsAppContact::withoutGlobalScopes()
                ->where('gabinete_id', $reminder->gabinete_id)
                ->where('usuario_id', (int) $recipient)
                ->first();
        if (! $contact) {
            return ReminderStatus::Failed;
        }

        $timezone = $appointment->gabinete->timezone ?? 'America/Fortaleza';
        $startsAt = CarbonImmutable::instance($appointment->inicio_em)->setTimezone($timezone);
        $name = $recipient === 'cidadao'
            ? (string) $appointment->cidadao?->nome
            : (string) ($appointment->responsavel_id === (int) $recipient
                ? $appointment->responsavel?->name
                : $appointment->participantes->firstWhere('id', (int) $recipient)?->name);
        $purpose = $recipient === 'cidadao'
            ? WhatsAppPurpose::AppointmentCitizenReminder
            : WhatsAppPurpose::AppointmentStaffReminder;
        $notification = $this->outbox->enqueue(
            $contact,
            $purpose,
            implode(':', ['appointment-reminder', $reminder->id, $reminder->agendado_para->timestamp, $recipient]),
            [
                $name !== '' ? $name : 'Participante',
                $startsAt->format('d/m/Y'),
                $startsAt->format('H:i'),
                trim((string) $appointment->local) ?: 'A confirmar',
            ],
            origin: $appointment,
        );

        return $notification ? ReminderStatus::Queued : ReminderStatus::Failed;
    }
}
