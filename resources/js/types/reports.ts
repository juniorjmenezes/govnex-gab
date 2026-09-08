import type { DashboardDatum } from './dashboard';
import type {
    DemandOrigin,
    DemandPriority,
    DemandStatus,
    SelectOption,
} from './demands';
import type { Pagination } from './registrations';

export type ReportFilters = {
    inicio: string;
    fim: string;
    status: string | null;
    prioridade: string | null;
    origem: string | null;
    categoria_id: number | null;
    bairro_id: number | null;
    responsavel_id: number | null;
    atrasadas: boolean;
};

export type ReportSummary = {
    total: number;
    open: number;
    resolved: number;
    closed: number;
    overdue: number;
    average_resolution_hours: number | null;
    resolution_rate: number;
    waiting_referrals: number;
};

export type ReportDemand = {
    id: number;
    protocol: string;
    title: string;
    status: DemandStatus;
    status_label: string;
    priority: DemandPriority;
    priority_label: string;
    origin: DemandOrigin;
    origin_label: string;
    citizen: string | null;
    category: string | null;
    neighborhood: string | null;
    responsible: string | null;
    opened_at: string;
    deadline: string | null;
    completed_at: string | null;
    overdue: boolean;
};

export type ProductivityRow = {
    id: number | null;
    name: string;
    assigned: number;
    resolved: number;
    average_resolution_hours: number | null;
};

export type WaitingReferral = {
    id: number;
    recipient: string;
    status: string;
    deadline: string | null;
    overdue: boolean;
    demand: { id: number; protocol: string; title: string } | null;
};

export type ReportExport = {
    id: string;
    format: 'pdf' | 'xlsx';
    format_label: string;
    status: 'pendente' | 'processando' | 'concluido' | 'falhou' | 'cancelado';
    status_label: string;
    file_name: string | null;
    size: number | null;
    error: string | null;
    created_at: string;
    completed_at: string | null;
    expires_at: string | null;
    requested_by: string | null;
    downloadable: boolean;
};

export type ReportPageProps = {
    filters: ReportFilters;
    summary: ReportSummary;
    charts: {
        status: DashboardDatum[];
        priority: DashboardDatum[];
        origin: DashboardDatum[];
        category: DashboardDatum[];
        neighborhood: DashboardDatum[];
        responsible: DashboardDatum[];
        monthly: DashboardDatum[];
    };
    productivity: ProductivityRow[];
    waitingReferrals: WaitingReferral[];
    demands: Pagination<ReportDemand>;
    options: {
        statuses: SelectOption[];
        priorities: SelectOption[];
        origins: SelectOption[];
        categories: Array<{ id: number; nome: string }>;
        neighborhoods: Array<{ id: number; nome: string }>;
        members: Array<{ id: number; name: string }>;
    };
    exports: ReportExport[];
};
