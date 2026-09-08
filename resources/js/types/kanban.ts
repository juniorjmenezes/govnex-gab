import type {
    Demand,
    DemandMember,
    DemandPriority,
    DemandStatus,
} from './demands';

export type DemandKanbanItem = Pick<
    Demand,
    | 'id'
    | 'protocolo'
    | 'titulo'
    | 'status'
    | 'prioridade'
    | 'aberta_em'
    | 'prazo'
    | 'concluida_em'
    | 'atrasada'
    | 'cidadao'
    | 'categoria'
    | 'bairro'
    | 'responsavel'
> & {
    anexos_count: number;
    allowed_transitions: DemandStatus[];
};

export type DemandKanbanColumn = {
    status: DemandStatus;
    label: string;
    total: number;
    truncated: boolean;
    demands: DemandKanbanItem[];
};

export type DemandKanbanTransitions = Record<DemandStatus, DemandStatus[]>;

export type DemandKanbanFilters = {
    q: string;
    prioridade: DemandPriority | '';
    responsavel_id: number | null;
};

export type DemandKanbanOptions = {
    priorities: Array<{ value: DemandPriority; label: string }>;
    members: DemandMember[];
};
