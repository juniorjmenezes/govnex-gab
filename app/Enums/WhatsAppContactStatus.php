<?php

namespace App\Enums;

enum WhatsAppContactStatus: string
{
    case Declared = 'DECLARED';
    case Revoked = 'REVOKED';
    case Invalid = 'INVALID';

    public function label(): string
    {
        return match ($this) {
            self::Declared => 'Declarado',
            self::Revoked => 'Revogado',
            self::Invalid => 'Inválido',
        };
    }
}
