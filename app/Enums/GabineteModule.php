<?php

namespace App\Enums;

enum GabineteModule: string
{
    case Relationship = 'RELACIONAMENTO';
    case Demands = 'DEMANDAS';
    case Attendances = 'ATENDIMENTOS';
    case Schedule = 'AGENDA';
    case Events = 'EVENTOS';
    case Politics = 'POLITICA';
    case Reports = 'RELATORIOS';
    case WhatsApp = 'WHATSAPP';

    public function label(): string
    {
        return match ($this) {
            self::Relationship => 'Relacionamento',
            self::Demands => 'Demandas',
            self::Attendances => 'Atendimentos',
            self::Schedule => 'Agenda',
            self::Events => 'Eventos',
            self::Politics => 'Inteligência política',
            self::Reports => 'Relatórios',
            self::WhatsApp => 'WhatsApp',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Relationship => 'Cidadãos, bairros e localização.',
            self::Demands => 'Demandas, categorias, Kanban e colaboração.',
            self::Attendances => 'Registro e histórico de atendimentos.',
            self::Schedule => 'Compromissos e lembretes.',
            self::Events => 'Eventos e participantes.',
            self::Politics => 'Painel político, mapa de eleitores e dados eleitorais.',
            self::Reports => 'Relatórios e exportações de demandas.',
            self::WhatsApp => 'Notificações operacionais pelo Gateway WhatsApp.',
        };
    }
}
