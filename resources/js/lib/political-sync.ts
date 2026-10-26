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

/**
 * Minutos/segundos corridos desde `since` até agora — em vez de um horário
 * fixo ("Processando desde 14:27"), que nunca muda na tela e faz qualquer
 * sincronização em andamento parecer travada mesmo quando está avançando
 * sozinha. Como a tela já faz polling a cada poucos segundos (ver
 * router.poll nas páginas que usam isto), o texto sobe a cada atualização.
 */
function elapsedSince(since: string): string {
    const ms = Date.now() - new Date(since).getTime();
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return minutes > 0 ? `${minutes} min ${seconds}s` : `${seconds}s`;
}

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
            return `Na fila há ${elapsedSince(sync.started_at)}`;
        case 'processando':
            if (sync.progress_percent !== null) {
                const stage =
                    progressStageLabels[sync.progress_stage ?? ''] ??
                    'Processando';

                return `${stage} — ${sync.progress_percent}%`;
            }

            return sync.processed_records > 0
                ? `${sync.processed_records.toLocaleString('pt-BR')} registros processados — ${elapsedSince(sync.started_at)}`
                : `Processando há ${elapsedSince(sync.started_at)}`;
        default:
            return sync.processed_records > 0
                ? `${sync.processed_records.toLocaleString('pt-BR')} registros atualizados`
                : `Atualizado em ${formatted}`;
    }
}

export type GovnexDatasetOption = {
    value: PoliticalDataSync['dataset'];
    description: string;
};

/**
 * Datasets do TSE na ordem em que dependem uns dos outros: a base de
 * municípios vincula tudo, as candidaturas resolvem o titular de cada
 * gabinete e a votação por seção só grava os votos desse titular.
 *
 * Só agrupa e descreve: quais valem para cada tipo de eleição é decidido no
 * backend (`TsePoliticalDataSyncService::datasetsFor()`), que manda à tela
 * da eleição apenas os do tipo dela.
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
                description:
                    'Perfil do eleitorado por município. Publicado um dataset por UF; entram as UFs já disponíveis na GOVNEX API.',
            },
            {
                value: 'candidates',
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
                description:
                    'Comparecimento e abstenção por município. Só existe a partir do dia da votação.',
            },
            {
                value: 'candidate_votes',
                description:
                    'Votos nominais de cada candidato a vereador por município.',
            },
            {
                value: 'polling_locations',
                description: 'Endereços e seções dos locais de votação.',
            },
            {
                value: 'section_votes',
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
                description:
                    'Número de registro oficial das pesquisas já sincronizadas. Não cria pesquisas nem resultados.',
            },
        ],
    },
];
