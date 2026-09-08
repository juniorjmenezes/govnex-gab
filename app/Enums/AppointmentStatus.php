<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'agendado';
    case Confirmed = 'confirmado';
    case Completed = 'concluido';
    case Cancelled = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendado',
            self::Confirmed => 'Confirmado',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }
}
