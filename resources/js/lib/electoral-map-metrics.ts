// Métricas selecionáveis para o mapa eleitoral: cada uma define como
// transformar um ElectoralMapPoint num valor comparável, usado tanto para
// colorir/dimensionar o mapa quanto para formatar popup, ranking e legenda.
//
// "performance" (Desempenho no local) fica com available:false de propósito:
// sua fórmula (votos_candidato_local / total_votos_válidos_local) precisa do
// total de votos válidos de TODOS os candidatos em cada local, e hoje o
// pipeline de sincronização (TsePoliticalDataSyncService::importSectionVotesArchive)
// só importa e armazena os votos do candidato titular de cada gabinete por
// seção — os demais candidatos daquela eleição nunca são gravados em
// votos_secao_candidato. Não há como calcular isso sem antes ampliar a
// sincronização (e aceitar o aumento de volume que isso implica). Ver notas
// no relatório da tarefa "Evolução do Mapa Eleitoral".
import type { ElectoralMapPoint } from '@/types';

export type MapMetric = 'votes' | 'percentage' | 'performance';

export type MetricDefinition = {
    value: MapMetric;
    label: string;
    /** Título curto usado como cabeçalho da legenda do mapa. */
    legendTitle: string;
    available: boolean;
    unavailableReason?: string;
    getValue: (point: ElectoralMapPoint, totalVotes: number) => number;
    format: (value: number) => string;
};

const integerFormatter = new Intl.NumberFormat('pt-BR');
const percentFormatter = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

export const mapMetrics: Record<MapMetric, MetricDefinition> = {
    votes: {
        value: 'votes',
        label: 'Votos',
        legendTitle: 'Intensidade por votos',
        available: true,
        getValue: (point) => point.votes,
        format: (value) =>
            `${integerFormatter.format(value)} ${value === 1 ? 'voto' : 'votos'}`,
    },
    percentage: {
        value: 'percentage',
        label: '% dos votos',
        legendTitle: 'Participação na votação',
        available: true,
        getValue: (point, totalVotes) =>
            totalVotes > 0 ? (point.votes / totalVotes) * 100 : 0,
        format: (value) => `${percentFormatter.format(value)}%`,
    },
    performance: {
        value: 'performance',
        label: 'Desempenho',
        legendTitle: 'Desempenho no local',
        available: false,
        unavailableReason:
            'Requer os votos de todos os candidatos de cada local (hoje só temos os do titular do gabinete). Ainda não disponível.',
        getValue: () => 0,
        format: (value) => `${percentFormatter.format(value)}%`,
    },
};

export const mapMetricOptions: MetricDefinition[] = [
    mapMetrics.votes,
    mapMetrics.percentage,
    mapMetrics.performance,
];
