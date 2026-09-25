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

    const items = data.filter((item) => item.total > 0);
    const summary = `Demandas por situação: ${items
        .map((item) => `${item.label} ${item.total}`)
        .join(', ')}.`;

    return (
        <div className="flex flex-col gap-5">
            <div className="flex items-baseline gap-2">
                <span className="text-3xl font-semibold tracking-tight tabular-nums">
                    {total.toLocaleString('pt-BR')}
                </span>
                <span className="text-sm text-muted-foreground">
                    {total === 1 ? 'demanda' : 'demandas'} no total
                </span>
            </div>
            {/* Barra segmentada (parte do todo): cada status ocupa a fração
                que tem, separado por uma fresta. Não depende da largura do
                cartão, ao contrário da rosca. */}
            <div
                className="flex h-2.5 w-full gap-1"
                role="img"
                aria-label={summary}
            >
                {items.map((item) => (
                    <span
                        key={item.key}
                        className="h-full min-w-1 rounded-full"
                        style={{
                            flexGrow: item.total,
                            flexBasis: 0,
                            backgroundColor:
                                statusColor[item.key as DemandStatus] ??
                                'var(--chart-5)',
                        }}
                    />
                ))}
            </div>
            <ul className="flex flex-col divide-y">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="flex items-center gap-2.5 py-2.5 text-sm first:pt-0 last:pb-0"
                    >
                        <span
                            className="size-2.5 shrink-0 rounded-full"
                            style={{
                                backgroundColor:
                                    statusColor[item.key as DemandStatus] ??
                                    'var(--chart-5)',
                            }}
                            aria-hidden="true"
                        />
                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                            {item.label}
                        </span>
                        <span className="font-medium tabular-nums">
                            {item.total.toLocaleString('pt-BR')}
                        </span>
                        <span className="w-10 text-right text-xs text-muted-foreground tabular-nums">
                            {percent.format(item.total / total)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
