import { EmptyState } from '@/components/feedback/empty-state';
import { InboxIcon } from '@/components/icons';
import type { DashboardDatum, DemandStatus } from '@/types';

/**
 * Cor de cada status = a mesma família do `StatusBadge` suave, para a barra e
 * o badge da tabela contarem a mesma história. "Em andamento" usa o
 * violeta categórico (`--chart-3`), não o destaque: com gabinete azul, ele se
 * confundiria com "Nova" (`--info`). "Encerrada" fica neutra.
 */
const statusColor: Record<DemandStatus, string> = {
    nova: 'var(--info)',
    em_andamento: 'var(--chart-3)',
    aguardando: 'var(--warning)',
    resolvida: 'var(--success)',
    encerrada: 'var(--muted-foreground)',
};

const percent = new Intl.NumberFormat('pt-BR', {
    style: 'percent',
    maximumFractionDigits: 0,
});

export function StatusOverview({ data }: { data: DashboardDatum[] }) {
    const total = data.reduce((sum, item) => sum + item.total, 0);

    if (total === 0) {
        return (
            <EmptyState
                size="compact"
                icon={InboxIcon}
                title="Sem demandas"
                description="A distribuição aparece conforme os atendimentos forem registrados."
            />
        );
    }

    // Maior fatia primeiro: vira a manchete e abre a barra segmentada — a
    // mesma leitura de cima para baixo do card de referência (percentual em
    // destaque, composição logo abaixo).
    const chartData = data
        .filter((item) => item.total > 0)
        .map((item) => ({
            ...item,
            fill: statusColor[item.key as DemandStatus] ?? 'var(--chart-5)',
        }))
        .sort((a, b) => b.total - a.total);
    const [top] = chartData;
    const summary = `Demandas por situação: ${chartData
        .map((item) => `${item.label} ${item.total}`)
        .join(', ')}.`;

    return (
        <div className="flex flex-col gap-4">
            <div>
                <p className="text-2xl font-semibold tracking-tight tabular-nums">
                    {percent.format(top.total / total)}{' '}
                    <span className="text-base font-normal text-muted-foreground">
                        {top.label.toLowerCase()}
                    </span>
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {total.toLocaleString('pt-BR')} demanda
                    {total === 1 ? '' : 's'} no período
                </p>
            </div>

            <div
                className="flex h-2 w-full gap-0.5"
                role="img"
                aria-label={summary}
            >
                {chartData.map((item) => (
                    <div
                        key={item.key}
                        className="h-full rounded-full first:rounded-l-full last:rounded-r-full"
                        style={{
                            width: `${(item.total / total) * 100}%`,
                            backgroundColor: item.fill,
                        }}
                    />
                ))}
            </div>

            {/* Grade de 2 colunas, não `flex-wrap`: com 5 status o texto
                quebra de qualquer forma, e a quebra ficava desalinhada. Em
                colunas fixas, a quebra faz parte do desenho. */}
            <ul className="grid grid-cols-2 gap-x-4 gap-y-2">
                {chartData.map((item) => (
                    <li
                        key={item.key}
                        className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <span
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: item.fill }}
                            aria-hidden="true"
                        />
                        <span className="truncate">{item.label}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
