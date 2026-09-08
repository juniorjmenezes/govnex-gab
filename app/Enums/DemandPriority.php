<?php

namespace App\Enums;

enum DemandPriority: string
{
    case Low = 'baixa';
    case Normal = 'normal';
    case High = 'alta';
    case Urgent = 'urgente';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixa',
            self::Normal => 'Normal',
            self::High => 'Alta',
            self::Urgent => 'Urgente',
        };
    }
}
