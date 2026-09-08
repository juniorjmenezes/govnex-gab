<?php

namespace App\Services\Appointments;

use App\Contracts\Appointments\AppointmentReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\AppointmentReminder;
use RuntimeException;

class FakeWhatsAppAppointmentReminderChannel implements AppointmentReminderChannel
{
    public function send(AppointmentReminder $reminder, string $recipient): ReminderStatus
    {
        if (! config('services.whatsapp.simulated', true)) {
            throw new RuntimeException('O provedor simulado de WhatsApp está desativado.');
        }

        $citizen = $reminder->compromisso->cidadao;

        if ($recipient !== 'cidadao' || ! $citizen?->whatsapp || ! $citizen->consentimento_contato) {
            throw new RuntimeException('Destinatário sem WhatsApp ou consentimento válido.');
        }

        return ReminderStatus::Simulated;
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55'.$digits;
        }

        if (! preg_match('/^[1-9]\d{9,14}$/', $digits)) {
            throw new RuntimeException('Telefone inválido para o padrão E.164.');
        }

        return '+'.$digits;
    }
}
