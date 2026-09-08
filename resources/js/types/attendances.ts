import type { Pagination } from './registrations';

export type AttendancePerson = {
    id: number;
    name: string;
    email?: string;
};

export type AttendanceCitizen = {
    id: number;
    nome: string;
    telefone?: string | null;
    whatsapp?: string | null;
    email?: string | null;
    eleitor: boolean;
};

export type AttendanceDemand = {
    id: number;
    cidadao_id?: number;
    protocolo: string;
    titulo: string;
    status?: string;
};

export type Attendance = {
    id: number;
    cidadao_id: number;
    atendente_id: number | null;
    demanda_id: number | null;
    criado_por_id: number;
    assunto: string;
    relato: string;
    providencias: string | null;
    atendido_em: string;
    duracao_minutos: number | null;
    requer_retorno: boolean;
    retorno_previsto_em: string | null;
    created_at: string;
    updated_at: string;
    cidadao: AttendanceCitizen;
    atendente: AttendancePerson | null;
    demanda: AttendanceDemand | null;
    criado_por?: AttendancePerson;
};

export type AttendanceOptions = {
    citizens: AttendanceCitizen[];
    members: AttendancePerson[];
    demands: AttendanceDemand[];
    capabilities: {
        demands: boolean;
    };
};

export type AttendanceIndexProps = {
    attendances: Pagination<Attendance>;
    filters: {
        q: string;
        atendente_id: number | null;
        de: string;
        ate: string;
        retorno: boolean;
    };
    members: AttendancePerson[];
    canDelete: boolean;
};
