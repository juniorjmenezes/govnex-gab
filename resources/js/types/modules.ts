export type GabineteModuleCode =
    | 'RELACIONAMENTO'
    | 'DEMANDAS'
    | 'ATENDIMENTOS'
    | 'AGENDA'
    | 'EVENTOS'
    | 'POLITICA'
    | 'RELATORIOS'
    | 'WHATSAPP'
    | 'BASE_CONHECIMENTO';

export type GabineteModuleDefinition = {
    code: GabineteModuleCode;
    name: string;
    description: string;
    dependencies: GabineteModuleCode[];
    any_of: GabineteModuleCode[];
};

export type GabineteModuleEvent = {
    id: number;
    module: GabineteModuleCode;
    action: 'ATIVADO' | 'DESATIVADO';
    administrator: string | null;
    occurred_at: string;
};
