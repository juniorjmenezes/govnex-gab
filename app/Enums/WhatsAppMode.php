<?php

namespace App\Enums;

enum WhatsAppMode: string
{
    case Off = 'OFF';
    case Pilot = 'PILOT';
    case Live = 'LIVE';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Desativado',
            self::Pilot => 'Piloto controlado',
            self::Live => 'Ativo',
        };
    }
}
