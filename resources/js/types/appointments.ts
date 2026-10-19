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
    /** `institutional`: publicado pela Secretaria legislativa da Câmara. */
    source: 'appointment' | 'event' | 'institutional';
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
    /** Só nos itens institucionais: quem publicou. */
    origin_office?: { id: number; name: string; type_label: string };
    /** Só nos itens institucionais: a própria Secretaria pode editar. */
    can_edit?: boolean;
};

export type AppointmentPageProps = {
    appointments: Appointment[];
    canDelete: boolean;
    /** Gabinete Secretaria legislativa com o módulo de gestão ativo. */
    canManageInstitutional: boolean;
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
    attendances: boolean;
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

/** Compromisso institucional como a página de edição recebe. */
export type InstitutionalAppointmentInput = {
    id: number;
    titulo: string;
    descricao: string | null;
    /** `yyyy-MM-ddTHH:mm`, já no fuso do gabinete. */
    inicio_em: string;
    fim_em: string;
    dia_inteiro: boolean;
    local: string | null;
    tipo: string;
    status: AppointmentStatus;
    /** Reunião de comissão: o público é o retrato dos membros ao vincular. */
    comissao_id: number | null;
    /** Vazio = todos os gabinetes de vereador da Câmara. */
    gabinetes: number[];
    is_past: boolean;
};

export type InstitutionalCommitteeOption = {
    id: number;
    name: string;
    is_active: boolean;
    /** Gabinetes dos membros ativos hoje (prévia de quem seria convocado). */
    offices: number[];
};

export type InstitutionalAppointmentFormProps = {
    appointment: InstitutionalAppointmentInput | null;
    today: string;
    timezone: string;
    defaults: { date: string };
    options: {
        statuses: Array<{ value: string; label: string }>;
        committees: InstitutionalCommitteeOption[];
        offices: Array<{ id: number; name: string; leader: string | null }>;
    };
    /**
     * Convocação por WhatsApp (só na edição). `available` já considera
     * permissão, situação do compromisso e o canal ligado na Câmara;
     * `offices` é o público atual.
     */
    broadcast: { available: boolean; offices: number } | null;
};
