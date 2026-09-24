import type { GabineteModuleCode, GabineteModuleEvent } from './modules';
import type { Pagination } from './registrations';

export type PlatformSummary = {
    offices: number;
    active_offices: number;
    suspended_offices: number;
    active_users: number;
    demands: number;
    demands_last_30_days: number;
    appointments_next_30_days: number;
    exports_last_30_days: number;
};

export type OfficeUsage = {
    id: number;
    name: string;
    status: 'ativo' | 'suspenso';
    status_label: string;
    city: string;
    state: string;
    users: number;
    citizens: number;
    demands: number;
    open_demands: number;
};

export type AdminOffice = {
    id: number;
    name: string;
    slug: string;
    status: 'ativo' | 'suspenso';
    status_label: string;
    /** Ligado ao Govnex Hub: nome e situação são definidos lá. */
    hub_linked: boolean;
    entidade: {
        id: number;
        name: string;
        slug: string;
        type: string;
    } | null;
    councilor_name: string;
    candidate_number: string | null;
    office_holder_candidate: {
        id: number;
        name: string;
        ballot_name: string;
        number: string | null;
        party: string | null;
    } | null;
    city: string;
    state: string;
    timezone: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    suspended_at: string | null;
    created_at: string;
    users_count: number;
    active_users_count: number;
    demands_count: number;
    open_demands_count: number;
    citizens_count: number;
    municipality_linked: boolean;
    municipality_tse_code: string | null;
    municipality_ibge_code: string | null;
    electorate_count: number | null;
    electorate_reference_date: string | null;
    political_data_checklist: PoliticalDataChecklistItem[];
    political_syncs: PoliticalDataSync[];
    modules: GabineteModuleCode[];
    module_history: GabineteModuleEvent[];
    responsible: {
        id: number;
        name: string;
        email: string;
        is_active: boolean;
        last_login_at: string | null;
    } | null;
};

export type PoliticalDataChecklistItem = {
    key: string;
    label: string;
    available: boolean;
    note: string | null;
};

export type PoliticalDataSync = {
    id: number;
    dataset:
        | 'municipalities'
        | 'electorate'
        | 'turnout'
        | 'candidate_votes'
        | 'candidates'
        | 'polling_locations'
        | 'section_votes'
        | 'poll_registry'
        | 'geocoding'
        | 'pollingdata_polls';
    year: number;
    /** Só preenchido para datasets segmentados por UF (ex.: eleitorado, votação por seção). */
    uf: string | null;
    status: 'pendente' | 'processando' | 'concluida' | 'falhou' | 'cancelada';
    processed_records: number;
    /** Só preenchido pelo pipeline do TSE, enquanto o job está lendo o arquivo, consultando a GOVNEX API ou gravando os registros. */
    progress_stage:
        'lendo_arquivo' | 'lendo_govnex_api' | 'gravando_registros' | null;
    /** Percentual (0-100) dentro da etapa atual — null quando não há progresso rastreado (ex.: geocoding, pollingdata_polls). */
    progress_percent: number | null;
    error: string | null;
    started_at: string;
    completed_at: string | null;
};

export type OfficePagination = Pagination<AdminOffice>;

/** Linha da tabela de PollingData na tela de sincronização política. */
export type PollingDataOffice = {
    id: number;
    name: string;
    entidade: string | null;
    city: string;
    state: string;
    latest_sync: PoliticalDataSync | null;
};
