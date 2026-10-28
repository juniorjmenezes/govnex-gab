<?php

namespace App\Enums;

enum EntityStatus: string
{
    case Active = 'ATIVA';
    case Suspended = 'SUSPENSA';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
