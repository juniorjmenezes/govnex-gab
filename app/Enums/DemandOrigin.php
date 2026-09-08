<?php

namespace App\Enums;

enum DemandOrigin: string
{
    case WhatsApp = 'whatsapp';
    case Phone = 'telefone';
    case InPerson = 'atendimento_presencial';
    case NeighborhoodVisit = 'visita_bairro';
    case SocialMedia = 'rede_social';
    case Email = 'email';
    case Other = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Phone => 'Telefone',
            self::InPerson => 'Atendimento presencial',
            self::NeighborhoodVisit => 'Visita ao bairro',
            self::SocialMedia => 'Rede social',
            self::Email => 'E-mail',
            self::Other => 'Outro',
        };
    }
}
