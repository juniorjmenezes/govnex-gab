import type { ReactNode } from 'react';

import { Card, CardContent } from '../components/card';
import { Progress } from '../components/progress';
import { Skeleton } from '../components/skeleton';
import { Sparkline } from '../components/sparkline';
import { ArrowRightDownIcon, ArrowRightUpIcon } from '../icons';
import { cn } from '../lib/utils';
import type { IconComponent } from '../types/icon';

export interface StatCardProgress {
    percent: number;
    label: string;
    value: string | number;
}

export interface StatCardTrend {
    /**
     * Variação vs período anterior: percentual (ex.: 12.5 ou -3) ou, com
     * `format="number"`, diferença absoluta (ex.: 4 ou -2).
     */
    value: number;
    /**
     * `percent` (padrão) exibe "+12,5%"; `number` exibe "+4" — preferível
     * para contagens pequenas, em que 1 → 2 viraria um alarmante "+100%", e
     * quando o período anterior é zero (percentual indefinido).
     */
    format?: 'percent' | 'number';
    /**
     * Direção exibida. Padrão: sinal de `value` (0 = estável). Informe para
     * casos em que o percentual é arredondado mas a direção é conhecida.
     */
    direction?: 'up' | 'down' | 'flat';
    /** Período de comparação, ex.: "vs. mês anterior". */
    label?: string;
    /**
     * Se subir é bom (padrão). Para indicadores como "Atrasadas", passe
     * `false`: alta fica vermelha e queda fica verde.
     */
    positiveIsGood?: boolean;
}

export interface StatCardProps {
    title: string;
    value: string | number;
    description?: ReactNode;
    progress?: StatCardProgress;
    icon?: IconComponent;
    valueClassName?: string;
    /** Variação vs período anterior, colorida semanticamente. */
    trend?: StatCardTrend;
    /** Série curta (do mais antigo ao atual) para o mini-gráfico. */
    sparkline?: readonly number[];
    /** Cor CSS do mini-gráfico. Padrão: destaque (`--chart-1`). */
    sparklineColor?: string;
    className?: string;
}

const percentFormatter = new Intl.NumberFormat('pt-BR', {
    maximumFractionDigits: 1,
});

function TrendBadge({ trend }: { trend: StatCardTrend }) {
    const direction =
        trend.direction ??
        (trend.value > 0 ? 'up' : trend.value < 0 ? 'down' : 'flat');
    const good = trend.positiveIsGood ?? true;
    const tone =
        direction === 'flat'
            ? 'text-muted-foreground'
            : (direction === 'up') === good
              ? 'text-success'
              : 'text-destructive';
    const Arrow =
        direction === 'up'
            ? ArrowRightUpIcon
            : direction === 'down'
              ? ArrowRightDownIcon
              : null;
    const unit = trend.format === 'number' ? '' : '%';
    const amount = `${percentFormatter.format(Math.abs(trend.value))}${unit}`;
    const spoken =
        direction === 'flat'
            ? 'Sem variação'
            : `${direction === 'up' ? 'Alta' : 'Queda'} de ${amount}`;

    return (
        <p className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs">
            <span
                className={cn(
                    'inline-flex items-center gap-0.5 font-medium tabular-nums',
                    tone,
                )}
            >
                {Arrow && <Arrow className="size-3.5" aria-hidden="true" />}
                <span aria-hidden="true">
                    {direction === 'flat'
                        ? trend.format === 'number'
                            ? 'Sem variação'
                            : amount
                        : `${direction === 'up' ? '+' : '−'}${amount}`}
                </span>
                <span className="sr-only">{spoken}</span>
            </span>
            {trend.label && (
                <span className="whitespace-nowrap text-muted-foreground">
                    {trend.label}
                </span>
            )}
        </p>
    );
}

/**
 * Indicador em cartão: rótulo, valor, variação opcional vs período anterior
 * e mini-gráfico opcional. `description` e `progress` continuam aceitos como
 * rodapé (compatível com a versão anterior).
 */
export function StatCard({
    title,
    value,
    description,
    progress,
    icon: Icon,
    valueClassName,
    trend,
    sparkline,
    sparklineColor,
    className,
}: StatCardProps) {
    const hasSparkline = sparkline !== undefined && sparkline.length > 1;

    return (
        <Card className={cn('min-w-0 gap-0 py-0', className)}>
            <CardContent className="flex h-full flex-col gap-3 p-5">
                <div className="flex items-start justify-between gap-3">
                    <p
                        className="min-w-0 truncate pt-1 text-sm font-medium text-muted-foreground"
                        title={title}
                    >
                        {title}
                    </p>
                    {Icon && (
                        <span
                            className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary dark:bg-primary/15 dark:text-[color-mix(in_oklch,var(--primary),white_35%)]"
                            aria-hidden="true"
                        >
                            <Icon className="size-5" />
                        </span>
                    )}
                </div>
                {/* Valor e mini-gráfico na mesma linha; a variação vem logo
                    abaixo em largura cheia, para não quebrar em cartões
                    estreitos (5 por linha). */}
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-end justify-between gap-3">
                        <p
                            className={cn(
                                'min-w-0 text-3xl font-semibold tracking-tight text-foreground tabular-nums',
                                valueClassName,
                            )}
                        >
                            {value}
                        </p>
                        {hasSparkline && (
                            <Sparkline
                                data={sparkline}
                                color={sparklineColor}
                                height={36}
                                className="mb-0.5 w-20 max-w-[50%] shrink"
                            />
                        )}
                    </div>
                    {trend && <TrendBadge trend={trend} />}
                </div>
                {progress ? (
                    <div className="mt-auto">
                        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>{progress.label}</span>
                            <span className="font-medium text-foreground tabular-nums">
                                {progress.value}
                            </span>
                        </div>
                        <Progress
                            value={Math.min(100, Math.max(0, progress.percent))}
                            className="mt-2"
                        />
                    </div>
                ) : description ? (
                    <p className="mt-auto text-xs leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}

/** Esqueleto de `StatCard` para estados de carregamento. */
export function StatCardSkeleton({ className }: { className?: string }) {
    return (
        <Card
            className={cn('min-w-0 gap-0 py-0', className)}
            aria-hidden="true"
        >
            <CardContent className="flex flex-col gap-3 p-5">
                <div className="flex items-start justify-between gap-3">
                    <Skeleton className="mt-1 h-4 w-28" />
                    <Skeleton className="size-9 rounded-lg" />
                </div>
                <Skeleton className="h-9 w-20" />
                <Skeleton className="h-3 w-36" />
            </CardContent>
        </Card>
    );
}
