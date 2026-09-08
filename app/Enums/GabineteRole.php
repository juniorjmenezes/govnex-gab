<?php

namespace App\Enums;

enum GabineteRole: string
{
    case Leader = 'LIDER';
    case Manager = 'GESTOR';
    case Member = 'MEMBRO';

    public function canManageGabinete(): bool
    {
        return in_array($this, [self::Leader, self::Manager], true);
    }
}
