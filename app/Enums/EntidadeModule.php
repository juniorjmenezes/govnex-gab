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
    case LegislativeSecretariat = 'SECRETARIA_LEGISLATIVA';

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
            self::LegislativeSecretariat => 'Secretaria legislativa',
        };
    }

    /**
     * Se a entidade deste tipo pode contratar o módulo. A Secretaria
     * legislativa só existe em Câmara Municipal.
     */
    public function availableFor(EntidadeType $tipo): bool
    {
        return $this !== self::LegislativeSecretariat || $tipo === EntidadeType::CityCouncil;
    }

    /** @return list<self> */
    public static function availableCases(EntidadeType $tipo): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $module): bool => $module->availableFor($tipo),
        ));
    }

    /**
     * Módulo opcional: nasce contratado, mas desligado, e a Câmara decide
     * quando ligar. Os demais nascem ligados, como sempre foram.
     */
    public function activeByDefault(): bool
    {
        return $this !== self::LegislativeSecretariat;
    }
}
