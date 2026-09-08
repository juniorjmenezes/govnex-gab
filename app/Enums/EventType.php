<?php

namespace App\Enums;

enum EventType: string
{
    case Meeting = 'reuniao';
    case PublicEvent = 'evento_publico';
    case PoliticalAct = 'ato_politico';
    case Assembly = 'assembleia';
    case Other = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Meeting => 'Reunião',
            self::PublicEvent => 'Evento público',
            self::PoliticalAct => 'Ato político',
            self::Assembly => 'Assembleia',
            self::Other => 'Outros',
        };
    }
}
