<?php

namespace App\Enums;

enum EntidadeModule: string
{
    case Relationship = 'RELACIONAMENTO';
    case Demands = 'DEMANDAS';
    case Attendances = 'ATENDIMENTOS';
    case Schedule = 'AGENDA';
    case Events = 'EVENTOS';
    case Politics = 'POLITICA';
    case Reports = 'RELATORIOS';
    case WhatsApp = 'WHATSAPP';
    case KnowledgeBase = 'BASE_CONHECIMENTO';

    public function scope(): ModuleScope
    {
        return ModuleScope::Both;
    }

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
            self::KnowledgeBase => 'Base de Conhecimento',
        };
    }
}
