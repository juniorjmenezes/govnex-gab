<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case Pending = 'pendente';
    case Processing = 'processando';
    case Simulated = 'simulado';
    case Queued = 'enfileirado';
    case Sent = 'enviado';
    case Failed = 'falhou';
    case Cancelled = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Simulated => 'Simulado',
            self::Queued => 'Enfileirado',
            self::Sent => 'Enviado',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}
