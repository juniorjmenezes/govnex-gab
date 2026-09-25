import { Pie, PieChart } from 'recharts';

import { EmptyState } from '@/components/feedback/empty-state';
import { InboxIcon } from '@/components/icons';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import type { DashboardDatum, DemandStatus } from '@/types';

/**
 * Cor de cada status = a mesma família do `StatusBadge` suave, para o anel e
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

    const chartData = data
        .filter((item) => item.total > 0)
        .map((item) => ({
            ...item,
            fill: statusColor[item.key as DemandStatus] ?? 'var(--chart-5)',
        }));
    const config: ChartConfig = {
        total: { label: 'Demandas' },
        ...Object.fromEntries(
            chartData.map((item) => [
                item.key,
                { label: item.label, color: item.fill },
            ]),
        ),
    };
    const summary = `Demandas por situação: ${chartData
        .map((item) => `${item.label} ${item.total}`)
        .join(', ')}.`;

    return (
        // Rosca ao lado da legenda quando o cartão comporta (container query),
        // empilhadas no estreito: o cartão secundário não fica alto e vazio.
        <div className="@container">
            <div className="flex flex-col items-center gap-4 @[19rem]:flex-row">
                <div className="relative size-28 shrink-0">
                    <ChartContainer
                        config={config}
                        className="aspect-square size-28"
                        role="img"
                        aria-label={summary}
                    >
                        {/* Rosca (parte do todo): uma fatia por status, separadas
                            por uma fresta na cor do cartão. */}
                        <PieChart accessibilityLayer>
                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        hideLabel
                                        nameKey="key"
                                    />
                                }
                            />
                            <Pie
                                data={chartData}
                                dataKey="total"
                                nameKey="key"
                                innerRadius="70%"
                                outerRadius="100%"
                                startAngle={90}
                                endAngle={-270}
                                stroke="var(--card)"
                                strokeWidth={2}
                                cornerRadius={3}
                                isAnimationActive={false}
                            />
                        </PieChart>
                    </ChartContainer>
                    <div
                        className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
                        aria-hidden="true"
                    >
                        <span className="text-xl font-semibold tracking-tight tabular-nums">
                            {total.toLocaleString('pt-BR')}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            demandas
                        </span>
                    </div>
                </div>
                <ul className="flex w-full min-w-0 flex-col gap-2">
                    {chartData.map((item) => (
                        <li
                            key={item.key}
                            className="flex items-center gap-2 text-sm"
                        >
                            <span
                                className="size-2 shrink-0 rounded-full"
                                style={{ backgroundColor: item.fill }}
                                aria-hidden="true"
                            />
                            <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                {item.label}
                            </span>
                            <span className="font-medium tabular-nums">
                                {item.total.toLocaleString('pt-BR')}
                            </span>
                            <span className="w-9 text-right text-xs text-muted-foreground tabular-nums">
                                {percent.format(item.total / total)}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
