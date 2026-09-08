<?php

namespace App\Enums;

enum ReminderChannel: string
{
    case Internal = 'interno';
    case FakeWhatsApp = 'whatsapp_simulado';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Notificação interna',
            self::FakeWhatsApp => 'WhatsApp simulado',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
