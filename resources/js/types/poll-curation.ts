import type { Pagination } from './registrations';

export type PollCurationElection = {
    id: number;
    name: string;
    year: number;
};

export type PollCurationResult = {
    candidato_politico_id: number | null;
    external_candidate_id: string;
    nome: string;
    partido: string | null;
    percentual: number;
};

export type PollCurationFonte = {
    id: number;
    tipo: string;
    provider: string;
    status: string;
    confidence_score: number;
    coletado_em: string | null;
    observacao: string | null;
};

export type PollCurationPesquisa = {
    id: number;
    eleicao_id: number;
    cargo: string;
    uf: string;
    municipio: string | null;
    turno: number;
    cenario: string;
    instituto: string | null;
    publicada_em: string;
    coleta_inicio_em: string | null;
    coleta_fim_em: string | null;
    tamanho_amostra: number | null;
    margem_erro: number | null;
    metodologia: string | null;
    abrangencia: string | null;
    tipo: string | null;
    fonte_url: string;
    confianca: number | null;
    origem_provider: string | null;
    deletable: boolean;
    resultados: PollCurationResult[];
    fontes: PollCurationFonte[];
};

export type PollCurationCandidateOption = {
    id: number;
    name: string;
    party: string | null;
    number: string | null;
};

export type PollCurationFilters = {
    eleicao_id: number | null;
    cargo: string;
    uf: string;
    q: string;
};

export type PollCurationProps = {
    elections: PollCurationElection[];
    filters: PollCurationFilters;
    pesquisas: Pagination<PollCurationPesquisa>;
};
