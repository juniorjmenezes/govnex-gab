import type { Pagination } from './registrations';

export type EventType =
    'reuniao' | 'evento_publico' | 'ato_politico' | 'assembleia' | 'outro';

export type EventStatus =
    'planejado' | 'confirmado' | 'em_andamento' | 'concluido' | 'cancelado';

export type EventDuration = 'unico_dia' | 'multiplos_dias';

export type OfficeEvent = {
    id: number;
    titulo: string;
    tipo: EventType;
    status: EventStatus;
    duracao: EventDuration;
    inicio_em: string;
    fim_em: string;
    local: string | null;
    responsavel_id: number | null;
    criado_por_id: number;
    descricao: string | null;
    observacoes: string | null;
    responsavel: { id: number; name: string; email?: string } | null;
    criado_por?: { id: number; name: string };
    participantes_usuarios?: Array<{
        id: number;
        name: string;
        email?: string;
    }>;
    participantes_cidadaos?: Array<{
        id: number;
        nome: string;
        email?: string | null;
        telefone?: string | null;
        whatsapp?: string | null;
    }>;
};

export type EventOptions = {
    types: Array<{ value: EventType; label: string }>;
    statuses: Array<{ value: EventStatus; label: string }>;
    durations: Array<{ value: EventDuration; label: string }>;
    members: Array<{ id: number; name: string }>;
    citizens: Array<{ id: number; nome: string }>;
};

export type EventIndexProps = {
    events: Pagination<OfficeEvent>;
    filters: {
        q: string;
        tipo: string;
        status: string;
        duracao: string;
        responsavel_id: number | null;
        de: string;
        ate: string;
        per_page: number;
    };
    options: EventOptions;
    canDelete: boolean;
};

export type DateTimeParts = {
    date: string;
    time: string;
};
