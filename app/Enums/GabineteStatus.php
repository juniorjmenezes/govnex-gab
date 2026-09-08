<?php

namespace App\Enums;

enum GabineteStatus: string
{
    case Active = 'ativo';
    case Suspended = 'suspenso';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Suspended => 'Suspenso',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
