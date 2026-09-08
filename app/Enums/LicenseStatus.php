<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case Active = 'ATIVA';
    case Trial = 'AVALIACAO';
    case Expired = 'EXPIRADA';
    case Suspended = 'SUSPENSA';

    public function permitsNewActions(): bool
    {
        return in_array($this, [self::Active, self::Trial], true);
    }
}
