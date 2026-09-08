<?php

namespace App\Enums;

enum ElectionType: string
{
    case General = 'geral';
    case Municipal = 'municipal';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Eleições Gerais',
            self::Municipal => 'Eleições Municipais',
        };
    }
}
