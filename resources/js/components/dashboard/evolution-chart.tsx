import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { EmptyState } from '@/components/feedback/empty-state';
import { GraphUpIcon } from '@/components/icons';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import type { DashboardMonthlyDatum } from '@/types';

const config = {
    total: { label: 'Entradas', color: 'var(--chart-1)' },
    resolved: { label: 'Resolvidas', color: 'var(--chart-2)' },
} satisfies ChartConfig;

const series = ['total', 'resolved'] as const;

/**
 * Entradas e resoluções por mês, em área com gradiente suave. Eixo Y sem
 * linha e com poucos ticks; a leitura exata fica no tooltip e no resumo
 * textual (lido por leitores de tela no lugar do SVG).
 */
export function EvolutionChart({ data }: { data: DashboardMonthlyDatum[] }) {
    const hasData = data.some((item) => item.total > 0 || item.resolved > 0);

    if (!hasData) {
        return (
            <EmptyState
                size="compact"
                icon={GraphUpIcon}
                title="Sem movimento no período"
                description="As entradas e resoluções aparecem aqui conforme as demandas forem registradas."
                className="h-full"
            />
        );
    }

    const totals = data.reduce(
        (sum, item) => ({
            total: sum.total + item.total,
            resolved: sum.resolved + item.resolved,
        }),
        { total: 0, resolved: 0 },
    );
    const summary = `Evolução mensal de ${data[0].label} a ${data[data.length - 1].label}: ${totals.total} entradas e ${totals.resolved} resoluções. ${data
        .map(
            (item) =>
                `${item.label}: ${item.total} entradas, ${item.resolved} resolvidas`,
        )
        .join('; ')}.`;

    return (
        <figure className="flex h-full flex-col gap-3">
            {/* Legenda com totais: identifica as séries (cor + nome) e já dá o número. */}
            <dl className="flex flex-wrap gap-x-8 gap-y-2">
                {series.map((key) => (
                    <div key={key} className="flex flex-col gap-0.5">
                        <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                            <span
                                className="h-0.5 w-3 rounded-full"
                                style={{ backgroundColor: config[key].color }}
                                aria-hidden="true"
                            />
                            {config[key].label}
                        </dt>
                        <dd className="text-xl font-semibold tracking-tight tabular-nums">
                            {totals[key].toLocaleString('pt-BR')}
                        </dd>
                    </div>
                ))}
            </dl>
            <ChartContainer
                config={config}
                className="aspect-auto h-64 w-full sm:h-72"
                role="img"
                aria-label={summary}
            >
                <AreaChart
                    data={data}
                    margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                    accessibilityLayer
                >
                    <defs>
                        {series.map((key) => (
                            <linearGradient
                                key={key}
                                id={`dashboard-evolution-${key}`}
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor={`var(--color-${key})`}
                                    stopOpacity={key === 'total' ? 0.28 : 0.18}
                                />
                                <stop
                                    offset="100%"
                                    stopColor={`var(--color-${key})`}
                                    stopOpacity={0}
                                />
                            </linearGradient>
                        ))}
                    </defs>
                    <CartesianGrid vertical={false} strokeOpacity={0.6} />
                    <XAxis
                        dataKey="label"
                        axisLine={false}
                        tickLine={false}
                        tickMargin={10}
                        minTickGap={24}
                        interval="preserveStartEnd"
                    />
                    <YAxis
                        allowDecimals={false}
                        axisLine={false}
                        tickLine={false}
                        tickCount={4}
                        width={32}
                    />
                    <ChartTooltip
                        cursor={{ stroke: 'var(--border)', strokeWidth: 1 }}
                        content={<ChartTooltipContent indicator="line" />}
                    />
                    {series.map((key) => (
                        <Area
                            key={key}
                            type="monotone"
                            dataKey={key}
                            stroke={`var(--color-${key})`}
                            strokeWidth={2}
                            fill={`url(#dashboard-evolution-${key})`}
                            dot={false}
                            activeDot={{
                                r: 4,
                                strokeWidth: 2,
                                stroke: 'var(--card)',
                            }}
                        />
                    ))}
                </AreaChart>
            </ChartContainer>
        </figure>
    );
}
