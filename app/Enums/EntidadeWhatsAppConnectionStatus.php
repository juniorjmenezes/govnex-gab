<?php

namespace App\Enums;

enum EntidadeWhatsAppConnectionStatus: string
{
    case Active = 'ATIVA';
    case Unavailable = 'INDISPONIVEL';
    case Revoked = 'REVOGADA';

    public function permitsSending(): bool
    {
        return $this === self::Active;
    }
}
