<?php

namespace App\Enums;

enum CommitteeType: string
{
    case Permanent = 'permanente';
    case Temporary = 'temporaria';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanente',
            self::Temporary => 'Temporária',
        };
    }
}
