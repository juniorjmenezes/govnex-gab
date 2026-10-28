<?php

namespace App\Enums;

enum OfficeModule: string
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
            self::KnowledgeBase => 'Biblioteca de PDFs para leitura da equipe.',
            self::LegislativeSecretariat => 'Gestão dos recursos compartilhados entre os gabinetes da Câmara.',
        };
    }

    /**
     * Se um gabinete deste tipo pode ativar o módulo. Sem tipo, vale o
     * padrão de qualquer gabinete. A gestão da Secretaria legislativa é
     * exclusiva do gabinete Secretaria — os vereadores só veem o que ela
     * compartilha, pelos módulos-base (Agenda, Base de Conhecimento).
     * A Secretaria, por sua vez, não atende cidadão nem faz política: fica
     * só com os módulos de `secretariatModules()`.
     */
    public function availableFor(?OfficeType $tipo): bool
    {
        if ($tipo === OfficeType::LegislativeSecretariat) {
            return in_array($this, self::secretariatModules(), true);
        }

        return $this !== self::LegislativeSecretariat;
    }

    /**
     * Módulos que o gabinete Secretaria legislativa pode ativar.
     *
     * @return list<self>
     */
    private static function secretariatModules(): array
    {
        return [self::Schedule, self::WhatsApp, self::KnowledgeBase, self::LegislativeSecretariat];
    }
}
