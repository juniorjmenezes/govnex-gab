<?php

namespace App\Enums;

enum EventDuration: string
{
    case SingleDay = 'unico_dia';
    case MultipleDays = 'multiplos_dias';

    public function label(): string
    {
        return match ($this) {
            self::SingleDay => 'Um dia',
            self::MultipleDays => 'Vários dias',
        };
    }
}
