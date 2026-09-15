import type { Pagination } from './registrations';

export type PoliticalElection = {
    id: number;
    name: string;
    year: number;
    type: 'geral' | 'municipal';
    type_label: string;
    first_round_at: string;
    second_round_at: string | null;
    source_updated_at: string | null;
};

export type PoliticalCandidate = {
    id: number;
    eleicao_id: number;
    sq_candidato: string;
    abrangencia: 'nacional' | 'estadual' | 'municipal';
    municipio_eleitoral_id: number | null;
    uf: string | null;
    cargo: string;
    nome: string;
    nome_urna: string;
    numero: string | null;
    partido_sigla: string | null;
    partido_nome: string | null;
    party_color: string | null;
    situacao: string | null;
    situacao_detalhada: string | null;
    foto_url: string | null;
    fonte_atualizada_em: string | null;
    is_favorite: boolean;
    /** Titular do gabinete: favorito fixo, não dá para desmarcar. */
    is_holder: boolean;
    news_count: number;
};

export type TseSyncSummary = {
    status: string;
    processed: number;
    started_at: string | null;
    completed_at: string | null;
};

export type PoliticalPollResult = {
    candidate_id: number | null;
    external_candidate_id: string;
    name: string;
    party: string | null;
    party_color: string | null;
    percentage: number;
    is_favorite: boolean;
};

export type PoliticalPoll = {
    id: number;
    external_id: string;
    institute: string;
    publication_date: string;
    fieldwork_start: string | null;
    fieldwork_end: string | null;
    sample_size: number | null;
    margin_of_error: number | null;
    methodology: string | null;
    scope: string | null;
    poll_type: string | null;
    source_url: string;
    source_updated_at: string | null;
    results: PoliticalPollResult[];
};

export type PoliticalPollAverage = PoliticalPollResult & {
    confidence_interval_low: number | null;
    confidence_interval_high: number | null;
    polls_included: number;
    total_sample_size: number;
    calculated_at: string | null;
    source_url: string | null;
    /** 'govnexgab': calculada a partir do histórico de pesquisas já sincronizado. 'electiolab': média consolidada histórica (fonte descontinuada, sem novos registros). */
    source: 'govnexgab' | 'electiolab';
};

export type PoliticalPollOffice = {
    slug: 'presidente' | 'governador' | 'senador' | 'prefeito';
    label: string;
    scope_label: string;
    senate_notice: string | null;
    has_aggregate: boolean;
    polls: PoliticalPoll[];
    averages: PoliticalPollAverage[];
};

export type PoliticalPolls = {
    source: string;
    source_url: string;
    state: string;
    municipality: string;
    election_type: 'geral' | 'municipal' | null;
    available: boolean;
    offices: PoliticalPollOffice[];
};

/** Apuração da eleição municipal selecionada, quando ela já foi realizada. */
export type PoliticalMunicipalElection = {
    election: { name: string; year: number; date: string };
    turnout: {
        eligible: number;
        voted: number;
        abstentions: number;
        percentage: number | null;
    } | null;
    /** Candidatos da disputa proporcional (vereador). */
    candidates: number;
    seats: number;
    /** Disputa majoritária, no turno que decidiu. Ausente sem dado de prefeito. */
    mayor: {
        round: number;
        candidates: {
            position: number;
            name: string;
            party: string | null;
            party_color: string | null;
            number: string | null;
            votes: number;
            elected: boolean;
        }[];
    } | null;
    holder: {
        name: string;
        party: string | null;
        number: string | null;
        votes: number;
        position: number;
        elected: boolean;
        result_status: string | null;
    } | null;
    elected: {
        position: number;
        name: string;
        party: string | null;
        party_color: string | null;
        number: string | null;
        votes: number;
        is_holder: boolean;
    }[];
    /** Não eleitos mais votados da disputa proporcional. */
    runners_up: {
        position: number;
        name: string;
        party: string | null;
        party_color: string | null;
        number: string | null;
        votes: number;
        is_holder: boolean;
    }[];
    parties: {
        party: string;
        seats: number;
        votes: number;
        /** Cor cadastrada em /admin/cores-partidos; nula cai no badge neutro. */
        color: string | null;
    }[];
};

export type PoliticalPanelProps = {
    elections: PoliticalElection[];
    selectedElectionId: number | null;
    candidates: Pagination<PoliticalCandidate>;
    filters: {
        eleicao_id: number | null;
        q: string;
        cargo: string;
        partido: string;
        favoritos: boolean;
    };
    options: {
        offices: string[];
        parties: string[];
    };
    stats: {
        official_eligible: number | null;
        official_reference: string | null;
        last_turnout: number | null;
        last_turnout_eligible: number | null;
        last_turnout_percentage: number | null;
        last_turnout_abstentions: number | null;
        last_election_name: string | null;
        last_election_date: string | null;
        last_election_round: number | null;
        holder_configured_number: string | null;
        holder_candidate_name: string | null;
        holder_candidate_party: string | null;
        holder_votes: number | null;
        holder_result_status: string | null;
        holder_elected: boolean | null;
        holder_election_name: string | null;
        internal_voters: number;
        coverage_percentage: number | null;
        favorites: number;
    };
    municipalElection: PoliticalMunicipalElection | null;
    municipality: {
        name: string;
        state: string;
        mapped: boolean;
        tse_code: string | null;
    };
    countdown: {
        label: string;
        target: string;
        date: string;
    } | null;
    serverNow: string;
    canFavorite: boolean;
    /** Ausente em eleição municipal já realizada: no lugar entra a apuração. */
    polls: PoliticalPolls | null;
    sync: {
        electorate: TseSyncSummary | null;
        turnout: TseSyncSummary | null;
        candidate_votes: TseSyncSummary | null;
        candidates: TseSyncSummary | null;
        polls: TseSyncSummary | null;
    };
};

/** Notícia de portal casada com um candidato favorito do gabinete. */
export type CandidateNews = {
    id: number;
    title: string;
    summary: string | null;
    url: string;
    image_url: string | null;
    published_at: string | null;
    source: string | null;
    candidate: string | null;
    candidate_party: string | null;
};
