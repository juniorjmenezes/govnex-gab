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

export type SyncTask = 'pollingdata_polls';

export type SyncOption = {
    value: SyncTask;
    label: string;
    description: string;
};

export const pollingDataSyncOptions: SyncOption[] = [
    {
        value: 'pollingdata_polls',
        label: 'Pesquisas de Presidente (PollingData)',
        description:
            'Importa pesquisas nacionais de intenção de voto para presidente. Não cobre governador, Senado ou prefeito.',
    },
];

export const syncOptions: SyncOption[] = pollingDataSyncOptions;

export type ManualUploadDataset =
    | 'municipalities'
    | 'candidates'
    | 'turnout'
    | 'candidate_votes'
    | 'polling_locations'
    | 'section_votes'
    | 'poll_registry';

export type ManualUploadOption = {
    value: ManualUploadDataset;
    requiresYear: boolean;
    requiresUf: boolean;
    description: string;
};

export const manualUploadDatasetOptions: ManualUploadOption[] = [
    {
        value: 'municipalities',
        requiresYear: false,
        requiresUf: false,
        description:
            'Base permanente da relação TSE/IBGE; importe uma vez e somente atualize quando o TSE publicar uma nova versão.',
    },
    {
        value: 'candidates',
        requiresYear: true,
        requiresUf: false,
        description:
            'Candidaturas da eleição selecionada. Aceita qualquer eleição já cadastrada (2024 ou 2026).',
    },
    {
        value: 'turnout',
        requiresYear: true,
        requiresUf: false,
        description:
            'Comparecimento e abstenção por município e zona. Aceita qualquer eleição cadastrada (2024 ou 2026); só existe a partir do dia da votação.',
    },
    {
        value: 'candidate_votes',
        requiresYear: true,
        requiresUf: false,
        description:
            'Votação nominal por município e zona. Somente eleição municipal — hoje, 2024. Não se aplica a 2026.',
    },
    {
        value: 'polling_locations',
        requiresYear: true,
        requiresUf: false,
        description:
            'Endereços dos locais de votação. O ZIP de 2024 contém um único CSV nacional. Somente eleição municipal — hoje, 2024.',
    },
    {
        value: 'section_votes',
        requiresYear: true,
        requiresUf: true,
        description:
            'Um ZIP separado por UF. Só aparecem UFs com gabinete ativo, município vinculado e titular localizado no TSE. Somente eleição municipal — hoje, 2024.',
    },
    {
        value: 'poll_registry',
        requiresYear: true,
        requiresUf: false,
        description:
            'Registro oficial das pesquisas eleitorais. Somente eleição geral — hoje, 2026. Não se aplica a 2024.',
    },
];

export function resolveTseSourceUrl(
    template: string | undefined,
    year: string,
    uf: string,
): string | null {
    if (!template) {
        return null;
    }

    if (template.includes('{year}') && !/^\d{4}$/.test(year)) {
        return null;
    }

    if (template.includes('{uf}') && !/^[A-Z]{2}$/.test(uf)) {
        return null;
    }

    return template.replaceAll('{year}', year).replaceAll('{uf}', uf);
}
