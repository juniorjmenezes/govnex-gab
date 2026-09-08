<?php

namespace App\Enums;

enum EventStatus: string
{
    case Planned = 'planejado';
    case Confirmed = 'confirmado';
    case InProgress = 'em_andamento';
    case Completed = 'concluido';
    case Cancelled = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planejado',
            self::Confirmed => 'Confirmado',
            self::InProgress => 'Em andamento',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }
}
