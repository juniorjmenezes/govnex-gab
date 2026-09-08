import type { DemandPriority, DemandStatus } from './demands';

export type DashboardDatum = {
    key: string;
    label: string;
    total: number;
};

export type DashboardMetrics = {
    open_total: number;
    new: number;
    in_progress: number;
    awaiting: number;
    for_today: number;
    overdue: number;
    resolved_month: number;
    citizens: number;
    average_resolution_hours: number | null;
    near_deadline: number;
};

export type DashboardDemand = {
    id: number;
    protocol: string;
    title: string;
    status: DemandStatus;
    priority: DemandPriority;
    opened_at: string;
    deadline: string | null;
    overdue: boolean;
    citizen: { id: number; name: string } | null;
    category: { id: number; name: string } | null;
    responsible: { id: number; name: string } | null;
};

export type DashboardAppointment = {
    id: number;
    title: string;
    starts_at: string;
    date: string;
    date_label: string;
    time_label: string;
    location: string | null;
    status: 'agendado' | 'confirmado';
    status_label: string;
    responsible: { id: number; name: string } | null;
};

export type DashboardActivity = {
    id: number;
    event: string;
    description: string;
    created_at: string;
    user: { id: number; name: string } | null;
    demand: { id: number; protocol: string; title: string } | null;
};

export type DashboardProps = {
    filters: { period: number; start: string; end: string };
    periodOptions: Array<{ value: number; label: string }>;
    capabilities: {
        relationship: boolean;
        demands: boolean;
        schedule: boolean;
        politics: boolean;
        whatsapp: boolean;
    };
    metrics: DashboardMetrics;
    charts: {
        status: DashboardDatum[];
        category: DashboardDatum[];
        neighborhood: DashboardDatum[];
        responsible: DashboardDatum[];
        origin: DashboardDatum[];
        monthly: DashboardDatum[];
    };
    upcomingAppointments: DashboardAppointment[];
    recentDemands: DashboardDemand[];
    attentionDemands: DashboardDemand[];
    upcomingDeadlines: DashboardDemand[];
    recentActivity: DashboardActivity[];
};
