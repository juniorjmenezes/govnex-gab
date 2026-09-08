<?php

namespace App\Enums;

enum EntidadeWhatsAppConnectionType: string
{
    case Own = 'PROPRIA';
    case Central = 'CENTRAL';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Conta própria da organização',
            self::Central => 'Conta central atribuída',
        };
    }
}
