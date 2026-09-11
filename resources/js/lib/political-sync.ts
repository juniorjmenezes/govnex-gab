import type { PoliticalDataSync } from '@/types';

export const datasetLabels: Record<string, string> = {
    municipalities: 'Município TSE/IBGE',
    electorate: 'Eleitorado atual',
    candidates: 'Candidaturas (TSE)',
    turnout: 'Comparecimento eleitoral',
    candidate_votes: 'Votação nominal',
    polling_locations: 'Locais de votação',
    section_votes: 'Votação por seção',
    geocoding: 'Geocodificação de locais',
    poll_registry: 'Registro de pesquisas (TSE)',
    pollingdata_polls: 'Pesquisas de Presidente (PollingData)',
};

export const syncStatusVariants: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pendente: 'outline',
    processando: 'secondary',
    concluida: 'default',
    falhou: 'destructive',
    cancelada: 'outline',
};

export const syncStatusLabels: Record<string, string> = {
    pendente: 'Pendente',
    processando: 'Processando',
    concluida: 'Concluída',
    falhou: 'Falhou',
    cancelada: 'Cancelada',
};

export function isActiveSync(sync: PoliticalDataSync): boolean {
    return sync.status === 'pendente' || sync.status === 'processando';
}

const progressStageLabels: Record<string, string> = {
    lendo_arquivo: 'Lendo arquivo',
    lendo_govnex_api: 'Consultando GOVNEX API',
    gravando_registros: 'Gravando registros',
};

export function syncStatusDetail(sync: PoliticalDataSync): string {
    const reference =
        sync.status === 'concluida' && sync.completed_at
            ? sync.completed_at
            : sync.started_at;
    const formatted = new Date(reference).toLocaleString('pt-BR');

    switch (sync.status) {
        case 'falhou':
        case 'cancelada':
            return sync.error ?? 'Cancelada';
        case 'pendente':
            return `Na fila desde ${formatted}`;
        case 'processando':
            if (sync.progress_percent !== null) {
                const stage =
                    progressStageLabels[sync.progress_stage ?? ''] ??
                    'Processando';

                return `${stage} — ${sync.progress_percent}%`;
            }

            return sync.processed_records > 0
                ? `${sync.processed_records.toLocaleString('pt-BR')} registros processados`
                : `Processando desde ${formatted}`;
        default:
            return sync.processed_records > 0
                ? `${sync.processed_records.toLocaleString('pt-BR')} registros atualizados`
                : `Atualizado em ${formatted}`;
    }
}

export type ElectionType = 'municipal' | 'geral';

export type ElectionOption = {
    year: number;
    type: ElectionType;
    label: string;
};

export type GovnexDatasetOption = {
    value: PoliticalDataSync['dataset'];
    description: string;
    /**
     * Eleição a que o dataset se aplica: `null` para a base permanente (sem
     * ano), `any` para qualquer eleição cadastrada.
     */
    election: ElectionType | 'any' | null;
};

/**
 * Datasets do TSE na ordem em que dependem uns dos outros: a base de
 * municípios vincula tudo, as candidaturas resolvem o titular de cada
 * gabinete e a votação por seção só grava os votos desse titular.
 */
export const govnexDatasetGroups: {
    title: string;
    options: GovnexDatasetOption[];
}[] = [
    {
        title: 'Base permanente',
        options: [
            {
                value: 'municipalities',
                election: null,
                description:
                    'Correspondência entre os códigos de município do TSE e do IBGE. Vincula cada gabinete ao seu município eleitoral — sincronize primeiro.',
            },
        ],
    },
    {
        title: 'Eleitorado e candidaturas',
        options: [
            {
                value: 'electorate',
                election: 'any',
                description:
                    'Perfil do eleitorado por município. Publicado um dataset por UF; entram as UFs já disponíveis na GOVNEX API.',
            },
            {
                value: 'candidates',
                election: 'any',
                description:
                    'Candidaturas do Brasil inteiro. Resolve o titular de cada gabinete.',
            },
        ],
    },
    {
        title: 'Resultados',
        options: [
            {
                value: 'turnout',
                election: 'any',
                description:
                    'Comparecimento e abstenção por município. Só existe a partir do dia da votação.',
            },
            {
                value: 'candidate_votes',
                election: 'municipal',
                description:
                    'Votos nominais de cada candidato a vereador por município.',
            },
            {
                value: 'polling_locations',
                election: 'municipal',
                description: 'Endereços e seções dos locais de votação.',
            },
            {
                value: 'section_votes',
                election: 'municipal',
                description:
                    'Votos do titular em cada seção, para o mapa eleitoral. Um dataset por UF; entram as UFs com gabinete e titular resolvido.',
            },
        ],
    },
    {
        title: 'Pesquisas',
        options: [
            {
                value: 'poll_registry',
                election: 'geral',
                description:
                    'Número de registro oficial das pesquisas já sincronizadas. Não cria pesquisas nem resultados.',
            },
        ],
    },
];
