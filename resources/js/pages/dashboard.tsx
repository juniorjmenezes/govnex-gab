import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { StatCard, StatCardSkeleton } from '@/components/common/stat-card';
import { toStatTrend } from '@/components/dashboard/dashboard-format';
import {
    AppointmentListItem,
    DemandListItem,
    RecentDemandsTable,
} from '@/components/dashboard/dashboard-lists';
import { EvolutionChart } from '@/components/dashboard/evolution-chart';
import { StatusOverview } from '@/components/dashboard/status-overview';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AddIcon,
    AltArrowRightIcon,
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
import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/ui/section-card';
import { useCanWrite } from '@/hooks/use-can-write';
import { contextualUrl } from '@/lib/entity-context';
import { cn } from '@/lib/utils';
import type { Auth, DashboardProps, DashboardTrendKey } from '@/types';
import type { IconComponent } from '@/types/icon';

type Kpi = {
    key: DashboardTrendKey;
    title: string;
    value: number;
    icon: IconComponent;
    alert?: boolean;
};

/** Rodapé de cartão com link "ver mais", alinhado à direita. */
function CardFooterLink({
    href,
    children,
}: {
    href: string;
    children: string;
}) {
    return (
        <div className="mt-auto flex justify-end border-t px-4 py-2.5">
            <Link
                href={href}
                className="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {children}
                <AltArrowRightIcon className="size-4" aria-hidden="true" />
            </Link>
        </div>
    );
}

export default function Dashboard({
    filters,
    periodOptions,
    metrics,
    trends,
    charts,
    capabilities,
    upcomingAppointments,
    recentDemands,
    attentionDemands,
    upcomingDeadlines,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const href = (path: string) => contextualUrl(auth, path);
    const canWrite = useCanWrite();
    const [loadingPeriod, setLoadingPeriod] = useState(false);
    const periodLabel =
        periodOptions.find((option) => option.value === filters.period)
            ?.label ?? `Últimos ${filters.period} dias`;

    const changePeriod = (value: string) => {
        router.get(
            href('/dashboard'),
            { period: Number(value) },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoadingPeriod(true),
                onFinish: () => setLoadingPeriod(false),
            },
        );
    };

    const kpis: Kpi[] = [
        ...(capabilities.demands
            ? ([
                  {
                      key: 'open_total',
                      title: 'Demandas abertas',
                      value: metrics.open_total,
                      icon: ClipboardListIcon,
                  },
                  {
                      key: 'overdue',
                      title: 'Atrasadas',
                      value: metrics.overdue,
                      icon: ClockCircleIcon,
                      alert: metrics.overdue > 0,
                  },
                  {
                      key: 'near_deadline',
                      title: 'Vencem em 7 dias',
                      value: metrics.near_deadline,
                      icon: CalendarMarkIcon,
                  },
                  {
                      key: 'resolved_period',
                      title: 'Resolvidas',
                      value: metrics.resolved_period,
                      icon: CheckCircleIcon,
                  },
              ] satisfies Kpi[])
            : []),
        ...(capabilities.relationship
            ? ([
                  {
                      key: 'citizens',
                      title: 'Cidadãos na base',
                      value: metrics.citizens,
                      icon: UsersGroupRoundedIcon,
                  },
              ] satisfies Kpi[])
            : []),
    ];

    return (
        <>
            <Head title="Visão geral" />
            <PageContainer>
                <PageHeader
                    title="Visão geral"
                    description={
                        capabilities.demands
                            ? 'Prioridades de hoje e evolução do atendimento do gabinete.'
                            : 'Acompanhe as funcionalidades habilitadas para este gabinete.'
                    }
                    actions={
                        capabilities.demands ? (
                            <>
                                <AppSelect
                                    className="w-auto min-w-44"
                                    clearable={false}
                                    aria-label="Período dos indicadores e gráficos"
                                    value={String(filters.period)}
                                    onValueChange={changePeriod}
                                    options={periodOptions.map((option) => ({
                                        value: String(option.value),
                                        label: option.label,
                                    }))}
                                />
                                {canWrite && (
                                    <Button asChild>
                                        <Link href={href('/demandas/create')}>
                                            <AddIcon aria-hidden="true" />
                                            Nova demanda
                                        </Link>
                                    </Button>
                                )}
                            </>
                        ) : undefined
                    }
                />

                {kpis.length > 0 && (
                    <section
                        aria-label="Indicadores principais"
                        aria-busy={loadingPeriod}
                        className={cn(
                            'grid gap-4 sm:grid-cols-2',
                            kpis.length >= 5
                                ? 'lg:grid-cols-3 xl:grid-cols-5 sm:max-lg:[&>*:last-child:nth-child(odd)]:col-span-2'
                                : kpis.length === 4
                                  ? 'xl:grid-cols-4'
                                  : 'lg:grid-cols-3',
                        )}
                    >
                        {kpis.map((kpi) =>
                            loadingPeriod ? (
                                <StatCardSkeleton key={kpi.key} />
                            ) : (
                                <StatCard
                                    key={kpi.key}
                                    title={kpi.title}
                                    value={kpi.value.toLocaleString('pt-BR')}
                                    icon={kpi.icon}
                                    valueClassName={
                                        kpi.alert
                                            ? 'text-destructive'
                                            : undefined
                                    }
                                    trend={toStatTrend(
                                        kpi.key,
                                        trends[kpi.key],
                                        {
                                            period: filters.period,
                                            start: filters.start,
                                        },
                                    )}
                                    sparkline={trends[kpi.key]?.series}
                                />
                            ),
                        )}
                    </section>
                )}

                {capabilities.demands ? (
                    /*
                     * Duas colunas independentes no desktop (2/3 + 1/3): cada
                     * cartão tem a altura do próprio conteúdo, sem esticar
                     * listas curtas ao lado do gráfico. No celular/tablet as
                     * colunas "somem" (`contents`) e a ordem vem de `order-*`,
                     * pondo "Atenção imediata" logo depois dos indicadores.
                     */
                    <div className="flex flex-col gap-6 xl:grid xl:grid-cols-3 xl:items-start">
                        <div className="contents xl:col-span-2 xl:flex xl:min-w-0 xl:flex-col xl:gap-6">
                            <SectionCard
                                title="Evolução do atendimento"
                                description={`Por mês · ${periodLabel.toLowerCase()}`}
                                className="order-2 xl:order-none"
                                contentClassName="p-5"
                            >
                                <EvolutionChart data={charts.monthly} />
                            </SectionCard>

                            <SectionCard
                                title="Demandas recentes"
                                description="Últimos registros no período"
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
                                className="order-4 xl:order-none"
                                contentClassName="p-0"
                            >
                                {recentDemands.length === 0 ? (
                                    <EmptyState
                                        size="compact"
                                        icon={ClipboardListIcon}
                                        title="Nenhuma demanda no período"
                                        description="Altere o período ou registre uma nova demanda."
                                    />
                                ) : (
                                    <RecentDemandsTable
                                        demands={recentDemands.slice(0, 6)}
                                        hrefFor={(demand) =>
                                            href(`/demandas/${demand.id}`)
                                        }
                                    />
                                )}
                            </SectionCard>
                        </div>

                        <div className="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-6">
                            <SectionCard
                                title="Atenção imediata"
                                description={
                                    metrics.overdue > 0
                                        ? `${metrics.overdue} com prazo vencido`
                                        : 'Prazos vencidos'
                                }
                                className="order-1 xl:order-none"
                                contentClassName="flex flex-col p-0"
                            >
                                {attentionDemands.length === 0 ? (
                                    <EmptyState
                                        size="compact"
                                        icon={CheckCircleIcon}
                                        title="Nenhuma demanda atrasada"
                                        description="Todos os prazos em aberto estão em dia."
                                    />
                                ) : (
                                    <>
                                        <ul className="divide-y">
                                            {attentionDemands.map((demand) => (
                                                <DemandListItem
                                                    key={demand.id}
                                                    demand={demand}
                                                    href={href(
                                                        `/demandas/${demand.id}`,
                                                    )}
                                                />
                                            ))}
                                        </ul>
                                        <CardFooterLink
                                            href={href('/demandas?tab=overdue')}
                                        >
                                            Ver todas as atrasadas
                                        </CardFooterLink>
                                    </>
                                )}
                            </SectionCard>

                            {/* Ao lado de "Atenção imediata": as duas listas de
                                prazo ficam juntas e as colunas se equilibram. */}
                            <SectionCard
                                title="Próximos prazos"
                                description="Vencem em 7 dias"
                                className="order-3 xl:order-none"
                                contentClassName="p-0"
                            >
                                {upcomingDeadlines.length === 0 ? (
                                    <EmptyState
                                        size="compact"
                                        icon={CalendarMarkIcon}
                                        title="Nenhum prazo na semana"
                                        description="Nenhuma demanda em aberto vence nos próximos 7 dias."
                                    />
                                ) : (
                                    <ul className="divide-y">
                                        {upcomingDeadlines.map((demand) => (
                                            <DemandListItem
                                                key={demand.id}
                                                demand={demand}
                                                href={href(
                                                    `/demandas/${demand.id}`,
                                                )}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </SectionCard>

                            {capabilities.schedule && (
                                <UpcomingAppointments
                                    appointments={upcomingAppointments}
                                    href={href}
                                    canWrite={canWrite}
                                    className="order-5 xl:order-none"
                                />
                            )}

                            <SectionCard
                                title="Situação atual"
                                description="Demandas por status"
                                className="order-6 xl:order-none"
                                contentClassName="p-5"
                            >
                                <StatusOverview data={charts.status} />
                            </SectionCard>
                        </div>
                    </div>
                ) : (
                    <div className="grid items-start gap-6 lg:grid-cols-2">
                        {capabilities.schedule && (
                            <UpcomingAppointments
                                appointments={upcomingAppointments}
                                href={href}
                                canWrite={canWrite}
                            />
                        )}
                        {!capabilities.schedule && (
                            <SectionCard
                                title="Painel preparado"
                                className="lg:col-span-2"
                                contentClassName="p-0"
                            >
                                <EmptyState
                                    icon={InboxIcon}
                                    title="Nada para acompanhar aqui ainda"
                                    description="Use o menu lateral para acessar os módulos habilitados para este gabinete."
                                />
                            </SectionCard>
                        )}
                    </div>
                )}
            </PageContainer>
        </>
    );
}

function UpcomingAppointments({
    appointments,
    href,
    canWrite,
    className,
}: {
    className?: string;
    appointments: DashboardProps['upcomingAppointments'];
    href: (path: string) => string;
    canWrite: boolean;
}) {
    return (
        <SectionCard
            title="Próximos compromissos"
            className={className}
            description="Hoje e próximos 7 dias"
            contentClassName="flex flex-col p-0"
        >
            {appointments.length === 0 ? (
                <EmptyState
                    size="compact"
                    icon={CalendarMarkIcon}
                    title="Agenda livre"
                    description="Nenhum compromisso marcado para os próximos 7 dias."
                    action={
                        <Button asChild size="sm" variant="outline">
                            <Link href={href('/agenda')}>
                                {canWrite
                                    ? 'Agendar compromisso'
                                    : 'Abrir agenda'}
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <>
                    <ul className="divide-y">
                        {appointments.map((appointment) => (
                            <AppointmentListItem
                                key={`${appointment.id}-${appointment.starts_at}`}
                                appointment={appointment}
                                href={href(
                                    `/agenda?view=dia&date=${appointment.date}`,
                                )}
                            />
                        ))}
                    </ul>
                    <CardFooterLink href={href('/agenda')}>
                        Abrir agenda
                    </CardFooterLink>
                </>
            )}
        </SectionCard>
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
