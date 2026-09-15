import { Head, Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';

import {
    Area,
    AreaChart,
    CartesianGrid,
    LabelList,
    RadialBar,
    RadialBarChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { StatCard } from '@/components/common/stat-card';
import { PriorityBadge } from '@/components/demands/priority-badge';
import { StatusBadge } from '@/components/demands/status-badge';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AddIcon,
    CalendarMarkIcon,
    CheckCircleIcon,
    ClipboardListIcon,
    ClockCircleIcon,
    InboxIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';

import { contextualUrl } from '@/lib/entity-context';
import type {
    Auth,
    DashboardDatum,
    DashboardDemand,
    DashboardProps,
} from '@/types';

const chartColors = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

const formatDate = (value: string | null) =>
    value
        ? format(new Date(value), "dd 'de' MMM", { locale: ptBR })
        : 'Sem prazo';

function DemandRow({
    demand,
    deadline = false,
}: {
    demand: DashboardDemand;
    deadline?: boolean;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <Link
            href={contextualUrl(auth, `/demandas/${demand.id}`)}
            className="group flex min-w-0 items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
        >
            <span className="shrink-0 text-sm font-normal tabular-nums">
                {demand.protocol}
            </span>
            <div className="shrink-0">
                <PriorityBadge priority={demand.priority} />
            </div>
            <span
                className="min-w-0 flex-1 truncate text-sm font-normal"
                title={demand.title}
            >
                {demand.title}
            </span>
            <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground">
                {deadline ? (
                    <span
                        className={
                            demand.overdue ? 'font-medium text-destructive' : ''
                        }
                    >
                        {demand.overdue ? 'Venceu em ' : 'Prazo: '}
                        {formatDate(demand.deadline)}
                    </span>
                ) : (
                    <>
                        {demand.citizen?.name ?? 'Cidadão não informado'} ·{' '}
                        {demand.responsible?.name ?? 'Sem responsável'}
                    </>
                )}
            </span>
            <div className="shrink-0">
                <StatusBadge status={demand.status} />
            </div>
        </Link>
    );
}

export default function Dashboard({
    filters,
    periodOptions,
    metrics,
    charts,
    capabilities,
    upcomingAppointments,
    recentDemands,
    attentionDemands,
    upcomingDeadlines,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const href = (path: string) => contextualUrl(auth, path);
    const statusData = charts.status.filter((item) => item.total > 0);
    const statusChartData = statusData.map((item, index) => ({
        ...item,
        fill: chartColors[index % chartColors.length],
    }));
    const statusChartConfig: ChartConfig = {
        total: { label: 'Demandas' },
        ...Object.fromEntries(
            statusChartData.map((item) => [
                item.key,
                { label: item.label, color: item.fill },
            ]),
        ),
    };
    const periodLabel =
        periodOptions.find((option) => option.value === filters.period)
            ?.label ?? `${filters.period} dias`;

    const changePeriod = (value: string) => {
        router.get(
            href('/dashboard'),
            { period: Number(value) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Visão geral" />
            <PageContainer>
                <PageHeader
                    title="Visão geral"
                    description={
                        capabilities.demands
                            ? 'Prioridades atuais e evolução do atendimento no período selecionado.'
                            : 'Acompanhe as funcionalidades habilitadas para este gabinete.'
                    }
                    actions={
                        capabilities.demands ? (
                            <>
                                <AppSelect
                                    className="w-auto min-w-44"
                                    aria-label="Período dos gráficos e demandas recentes"
                                    value={String(filters.period)}
                                    onValueChange={changePeriod}
                                    options={periodOptions.map((option) => ({
                                        value: String(option.value),
                                        label: option.label,
                                    }))}
                                />
                                <Button asChild>
                                    <Link href={href('/demandas/create')}>
                                        <AddIcon aria-hidden="true" />
                                        Nova demanda
                                    </Link>
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                {!capabilities.demands && capabilities.relationship && (
                    <section
                        aria-label="Indicadores de relacionamento"
                        className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <StatCard
                            title="Cidadãos cadastrados"
                            value={metrics.citizens}
                            description="Base de relacionamento do gabinete"
                            icon={UsersGroupRoundedIcon}
                        />
                    </section>
                )}

                {capabilities.demands && (
                    <section
                        aria-label="Indicadores principais"
                        className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <StatCard
                            title="Demandas abertas"
                            value={metrics.open_total}
                            description="Em atendimento agora"
                            icon={ClipboardListIcon}
                        />
                        <StatCard
                            title="Atrasadas"
                            value={metrics.overdue}
                            description="Exigem ação imediata"
                            icon={ClockCircleIcon}
                            valueClassName={
                                metrics.overdue > 0
                                    ? 'text-destructive'
                                    : undefined
                            }
                        />
                        <StatCard
                            title="Resolvidas no mês"
                            value={metrics.resolved_month}
                            description="Finalizadas neste mês"
                            icon={CheckCircleIcon}
                        />
                        <StatCard
                            title="Próximas do prazo"
                            value={metrics.near_deadline}
                            description="Vencem nos próximos 7 dias"
                            icon={CalendarMarkIcon}
                        />
                    </section>
                )}

                {capabilities.schedule && (
                    <Surface
                        as="section"
                        aria-labelledby="dashboard-agenda-title"
                        className="overflow-hidden"
                    >
                        <SurfaceHeader
                            actions={
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="shrink-0"
                                >
                                    <Link href={href('/agenda')}>
                                        Abrir agenda
                                    </Link>
                                </Button>
                            }
                        >
                            <SurfaceTitle id="dashboard-agenda-title">
                                Próximos compromissos
                            </SurfaceTitle>
                            <SurfaceDescription>
                                Agenda de hoje e dos próximos 7 dias
                            </SurfaceDescription>
                        </SurfaceHeader>
                        {upcomingAppointments.length === 0 ? (
                            <div className="flex flex-col gap-3 px-5 py-6 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                                <span>Nenhum compromisso próximo.</span>
                                <Button asChild size="sm" variant="outline">
                                    <Link href={href('/agenda')}>
                                        Novo compromisso
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
                                {upcomingAppointments.map((appointment) => (
                                    <Link
                                        key={`${appointment.id}-${appointment.starts_at}`}
                                        href={href(
                                            `/agenda?view=dia&date=${appointment.date}`,
                                        )}
                                        className="group min-w-0 rounded-xl bg-muted/35 p-4 ring-1 ring-foreground/8 transition-colors hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="text-xs font-semibold text-primary">
                                                    {appointment.date_label}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {appointment.time_label}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    appointment.status ===
                                                    'confirmado'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {appointment.status_label}
                                            </Badge>
                                        </div>
                                        <p className="mt-3 truncate text-sm font-semibold">
                                            {appointment.title}
                                        </p>
                                        <p className="mt-1 truncate text-xs text-muted-foreground">
                                            {appointment.responsible?.name ??
                                                appointment.location ??
                                                'Sem responsável definido'}
                                        </p>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </Surface>
                )}

                {!capabilities.demands && !capabilities.schedule && (
                    <Surface as="section">
                        <EmptyState
                            icon={InboxIcon}
                            title="Painel preparado"
                            description="Use o menu lateral para acessar os módulos habilitados para este gabinete."
                        />
                    </Surface>
                )}

                {capabilities.demands && (
                    <>
                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle>Atenção imediata</SurfaceTitle>
                                <SurfaceDescription>
                                    Demandas com prazo vencido
                                </SurfaceDescription>
                            </SurfaceHeader>
                            {attentionDemands.length === 0 ? (
                                <div className="px-5 py-8 text-center text-sm text-muted-foreground">
                                    Nenhuma demanda atrasada.
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {attentionDemands.map((demand) => (
                                        <DemandRow
                                            key={demand.id}
                                            demand={demand}
                                            deadline
                                        />
                                    ))}
                                </div>
                            )}
                        </Surface>

                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader
                                actions={
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0"
                                    >
                                        <Link href={href('/demandas')}>
                                            Ver todas
                                        </Link>
                                    </Button>
                                }
                            >
                                <SurfaceTitle>Demandas recentes</SurfaceTitle>
                                <SurfaceDescription>
                                    Últimos registros no período
                                </SurfaceDescription>
                            </SurfaceHeader>
                            {recentDemands.length === 0 ? (
                                <EmptyState
                                    icon={ClipboardListIcon}
                                    title="Nenhuma demanda no período"
                                    description="Altere o período ou registre uma nova demanda."
                                />
                            ) : (
                                <div className="divide-y">
                                    {recentDemands.slice(0, 6).map((demand) => (
                                        <DemandRow
                                            key={demand.id}
                                            demand={demand}
                                        />
                                    ))}
                                </div>
                            )}
                        </Surface>

                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle>Próximos prazos</SurfaceTitle>
                                <SurfaceDescription>
                                    Vencem nos próximos 7 dias
                                </SurfaceDescription>
                            </SurfaceHeader>
                            {upcomingDeadlines.length === 0 ? (
                                <div className="px-5 py-8 text-center text-sm text-muted-foreground">
                                    Nenhum prazo próximo.
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {upcomingDeadlines.map((demand) => (
                                        <DemandRow
                                            key={demand.id}
                                            demand={demand}
                                            deadline
                                        />
                                    ))}
                                </div>
                            )}
                        </Surface>

                        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.8fr)]">
                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader>
                                    <SurfaceTitle>
                                        Evolução das entradas
                                    </SurfaceTitle>
                                    <SurfaceDescription>
                                        Demandas abertas ·{' '}
                                        {periodLabel.toLowerCase()}
                                    </SurfaceDescription>
                                </SurfaceHeader>
                                <div
                                    className="h-72 p-4"
                                    aria-label="Gráfico da evolução mensal das demandas"
                                >
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={charts.monthly}
                                            margin={{
                                                top: 8,
                                                right: 4,
                                                bottom: 0,
                                                left: 0,
                                            }}
                                            accessibilityLayer
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="dashboardEntriesArea"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="var(--chart-1)"
                                                        stopOpacity={0.42}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="var(--chart-1)"
                                                        stopOpacity={0.04}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                vertical={false}
                                            />
                                            <XAxis
                                                dataKey="label"
                                                axisLine={false}
                                                tickLine={false}
                                                tickMargin={10}
                                                minTickGap={24}
                                                interval="preserveStartEnd"
                                                tick={{ fontSize: 11 }}
                                            />
                                            <YAxis
                                                allowDecimals={false}
                                                axisLine={false}
                                                tickLine={false}
                                                tick={{ fontSize: 11 }}
                                                width={28}
                                            />
                                            <Tooltip
                                                cursor={{
                                                    stroke: 'var(--border)',
                                                }}
                                                content={({
                                                    active,
                                                    payload,
                                                }) => {
                                                    const datum = payload?.[0]
                                                        ?.payload as
                                                        | DashboardDatum
                                                        | undefined;

                                                    if (!active || !datum) {
                                                        return null;
                                                    }

                                                    return (
                                                        <div className="max-w-72 min-w-44 rounded-xl border bg-popover px-3 py-2.5 text-popover-foreground shadow-md">
                                                            <p className="text-xs font-medium break-words">
                                                                {datum.label}
                                                            </p>
                                                            <div className="mt-2 flex items-center gap-2 text-xs">
                                                                <span
                                                                    className="size-2.5 shrink-0 rounded-[3px]"
                                                                    style={{
                                                                        backgroundColor:
                                                                            'var(--chart-1)',
                                                                    }}
                                                                    aria-hidden="true"
                                                                />
                                                                <span className="text-muted-foreground">
                                                                    Demandas
                                                                </span>
                                                                <span className="ml-auto pl-4 font-medium tabular-nums">
                                                                    {datum.total.toLocaleString(
                                                                        'pt-BR',
                                                                    )}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    );
                                                }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="total"
                                                name="Demandas"
                                                stroke="var(--chart-1)"
                                                strokeWidth={2.5}
                                                fill="url(#dashboardEntriesArea)"
                                                dot={{
                                                    r: 3,
                                                    fill: 'var(--background)',
                                                    strokeWidth: 2,
                                                }}
                                                activeDot={{
                                                    r: 5,
                                                    strokeWidth: 2,
                                                }}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            </Surface>

                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader>
                                    <SurfaceTitle>Situação atual</SurfaceTitle>
                                    <SurfaceDescription>
                                        Distribuição por status
                                    </SurfaceDescription>
                                </SurfaceHeader>
                                {statusData.length === 0 ? (
                                    <EmptyState
                                        icon={InboxIcon}
                                        title="Sem demandas"
                                        description="Os status aparecerão conforme os atendimentos forem registrados."
                                    />
                                ) : (
                                    <div className="p-4">
                                        <ChartContainer
                                            config={statusChartConfig}
                                            className="mx-auto aspect-square max-h-[250px]"
                                            aria-label="Gráfico radial das demandas por status"
                                        >
                                            <RadialBarChart
                                                data={statusChartData}
                                                startAngle={-90}
                                                endAngle={380}
                                                innerRadius={30}
                                                outerRadius={110}
                                                accessibilityLayer
                                            >
                                                <ChartTooltip
                                                    cursor={false}
                                                    content={
                                                        <ChartTooltipContent
                                                            hideLabel
                                                            nameKey="key"
                                                        />
                                                    }
                                                />
                                                <RadialBar
                                                    dataKey="total"
                                                    background
                                                    barSize={16}
                                                >
                                                    <LabelList
                                                        position="insideStart"
                                                        dataKey="label"
                                                        className="fill-white font-medium mix-blend-luminosity"
                                                        fontSize={11}
                                                    />
                                                </RadialBar>
                                            </RadialBarChart>
                                        </ChartContainer>
                                    </div>
                                )}
                            </Surface>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Visão geral',
            href: '#',
        },
    ],
};
