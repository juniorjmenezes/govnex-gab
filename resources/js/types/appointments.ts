export type AppointmentStatus =
    'agendado' | 'confirmado' | 'concluido' | 'cancelado';

export type ReminderChannel = 'interno' | 'whatsapp_simulado' | 'whatsapp';
export type ReminderStatus =
    | 'pendente'
    | 'processando'
    | 'simulado'
    | 'enfileirado'
    | 'enviado'
    | 'falhou'
    | 'cancelado';

export type AppointmentReminder = {
    id: number;
    channel: ReminderChannel;
    channel_label: string;
    minutes_before: number;
    recipients: Array<number | string>;
    active: boolean;
    scheduled_for: string;
    status: ReminderStatus;
    status_label: string;
    error: string | null;
    attempts: Array<{
        id: number;
        recipient: string;
        status: ReminderStatus;
        status_label: string;
        attempts: number;
        error: string | null;
    }>;
};

export type Appointment = {
    id: number;
    source: 'appointment' | 'event';
    occurrence_key: string;
    title: string;
    description: string | null;
    starts_at: string;
    ends_at: string;
    series_starts_at: string;
    series_ends_at: string;
    all_day: boolean;
    location: string | null;
    type: string;
    status: AppointmentStatus;
    status_label: string;
    is_past: boolean;
    notes: string | null;
    recurrence: 'nenhuma' | 'diaria' | 'semanal' | 'mensal';
    recurrence_until: string | null;
    responsible: { id: number; name: string } | null;
    participants: Array<{ id: number; name: string }>;
    citizen: {
        id: number;
        nome: string;
        whatsapp: string | null;
        consentimento_contato: boolean;
    } | null;
    demand: { id: number; protocolo: string; titulo: string } | null;
    created_by: { id: number; name: string };
    reminders: AppointmentReminder[];
};

export type AppointmentPageProps = {
    appointments: Appointment[];
    canDelete: boolean;
    filters: {
        view: 'mes' | 'semana' | 'dia' | 'lista';
        date: string;
        responsavel_id: number | null;
        status: string;
        tipo: string;
    };
    range: { start: string; end: string };
    timezone: string;
    today: string;
    whatsappSimulated: boolean;
    whatsappRealEnabled: boolean;
    capabilities: AppointmentCapabilities;
    options: AppointmentOptions;
};

export type AppointmentCapabilities = {
    events: boolean;
    demands: boolean;
    whatsapp: boolean;
};

/** Listas que alimentam o formulário de compromisso (página e modal). */
export type AppointmentOptions = {
    statuses: Array<{ value: string; label: string }>;
    recurrences: Array<{ value: string; label: string }>;
    channels: Array<{ value: string; label: string }>;
    members: Array<{
        id: number;
        name: string;
        whatsapp_ready: boolean;
    }>;
    citizens: Array<{
        id: number;
        nome: string;
        whatsapp: string | null;
        consentimento_contato: boolean;
        whatsapp_ready: boolean;
    }>;
    demands: Array<{ id: number; protocolo: string; titulo: string }>;
};
