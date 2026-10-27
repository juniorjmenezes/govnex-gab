import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { StatCard, StatCardSkeleton } from '@/components/common/stat-card';
import { toStatTrend } from '@/components/dashboard/dashboard-format';
import {
    AppointmentCard,
    DemandListItem,
    RecentDemandsTable,
} from '@/components/dashboard/dashboard-lists';
import { EvolutionChart } from '@/components/dashboard/evolution-chart';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AddIcon,
    AltArrowRightIcon,
    CalendarMarkIcon,
    CheckCircleIcon,
    ClipboardListIcon,
    ClockCircleIcon,
    DocumentTextIcon,
    InboxIcon,
    UsersGroupRoundedIcon,
    UsersGroupTwoRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { CardCarousel } from '@/components/ui/card-carousel';
import { SectionCard } from '@/components/ui/section-card';
import { useCanWrite } from '@/hooks/use-can-write';
import { contextualUrl } from '@/lib/entity-context';
import { cn } from '@/lib/utils';
import type {
    Auth,
    DashboardProps,
    DashboardTrendKey,
    DemandStatus,
} from '@/types';
import type { IconComponent } from '@/types/icon';

/**
 * Mesma família de cor do `StatusBadge` suave, para o rodapé contar a mesma
 * história da tabela. "Em andamento" usa o violeta categórico (`--chart-3`),
 * não o destaque: com gabinete azul, ele se confundiria com "Nova"
 * (`--info`). "Encerrada" fica neutra.
 */
const statusColor: Record<DemandStatus, string> = {
    nova: 'var(--info)',
    em_andamento: 'var(--chart-3)',
    aguardando: 'var(--warning)',
    resolvida: 'var(--success)',
    encerrada: 'var(--muted-foreground)',
};

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
    legislativeSecretariat,
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
                            : capabilities.legislativeSecretariat
                              ? 'Compromissos e comissões da Câmara.'
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

                {capabilities.legislativeSecretariat &&
                    legislativeSecretariat && (
                        <section
                            aria-label="Indicadores da Secretaria legislativa"
                            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <StatCard
                                title="Compromissos hoje"
                                value={legislativeSecretariat.appointments_today.toLocaleString(
                                    'pt-BR',
                                )}
                                icon={CalendarMarkIcon}
                            />
                            <StatCard
                                title="Comissões ativas"
                                value={legislativeSecretariat.active_committees.toLocaleString(
                                    'pt-BR',
                                )}
                                icon={UsersGroupTwoRoundedIcon}
                            />
                            <StatCard
                                title="Membros em comissões"
                                value={legislativeSecretariat.committee_members.toLocaleString(
                                    'pt-BR',
                                )}
                                icon={UsersGroupRoundedIcon}
                            />
                            <StatCard
                                title="Documentos recentes"
                                value={legislativeSecretariat.recent_documents.toLocaleString(
                                    'pt-BR',
                                )}
                                icon={DocumentTextIcon}
                            />
                        </section>
                    )}

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
                     * Fileiras de largura cheia, nunca coluna estreita ao lado
                     * de coluna larga: quando dois cartões dividem uma linha,
                     * eles têm a mesma largura e a mesma altura (grade, não
                     * duas colunas que crescem cada uma pela própria conta).
                     * É o que evita a borda de baixo desencontrada entre
                     * cartões vizinhos, em qualquer largura de tela.
                     */
                    <div className="flex flex-col gap-6">
                        <SectionCard
                            title="Evolução do atendimento"
                            description={`Por mês · ${periodLabel.toLowerCase()}`}
                            contentClassName="p-0"
                        >
                            <div className="p-5">
                                <EvolutionChart data={charts.monthly} />
                            </div>
                            {/* Composição por status como rodapé: só os
                                quantitativos, sem disputar outro gráfico com
                                a evolução acima. */}
                            {charts.status.some((item) => item.total > 0) && (
                                <div className="flex flex-wrap items-center justify-center gap-x-0 gap-y-2 divide-x border-t px-5 py-3">
                                    {charts.status
                                        .filter((item) => item.total > 0)
                                        .map((item) => (
                                            <div
                                                key={item.key}
                                                className="flex items-center gap-1.5 px-4 text-sm text-muted-foreground first:pl-0 last:pr-0"
                                            >
                                                <span
                                                    className="size-2 shrink-0 rounded-[2px]"
                                                    style={{
                                                        backgroundColor:
                                                            statusColor[
                                                                item.key as DemandStatus
                                                            ] ??
                                                            'var(--chart-5)',
                                                    }}
                                                    aria-hidden="true"
                                                />
                                                {item.label}
                                                <span className="font-medium text-foreground tabular-nums">
                                                    {item.total}
                                                </span>
                                            </div>
                                        ))}
                                </div>
                            )}
                        </SectionCard>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <SectionCard
                                title="Atenção imediata"
                                description={
                                    metrics.overdue > 0
                                        ? `${metrics.overdue} com prazo vencido`
                                        : 'Prazos vencidos'
                                }
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

                            <SectionCard
                                title="Próximos prazos"
                                description="Vencem em 7 dias"
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
                        </div>

                        {capabilities.schedule && (
                            <UpcomingAppointments
                                appointments={upcomingAppointments}
                                href={href}
                                canWrite={canWrite}
                            />
                        )}

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
                ) : capabilities.legislativeSecretariat ? (
                    <div className="grid items-start gap-6 lg:grid-cols-2">
                        {capabilities.schedule && (
                            <UpcomingAppointments
                                appointments={upcomingAppointments}
                                href={href}
                                canWrite={canWrite}
                            />
                        )}
                        <SectionCard
                            title="Comissões legislativas"
                            description="Ativas e com membros em exercício"
                            actions={
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="shrink-0"
                                >
                                    <Link href={href('/comissoes')}>
                                        Ver comissões
                                    </Link>
                                </Button>
                            }
                            contentClassName="p-0"
                        >
                            {legislativeSecretariat?.active_committees === 0 ? (
                                <EmptyState
                                    size="compact"
                                    icon={UsersGroupTwoRoundedIcon}
                                    title="Nenhuma comissão ativa"
                                    description="Cadastre as comissões da Câmara para acompanhar aqui."
                                    action={
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={href('/comissoes')}>
                                                Cadastrar comissão
                                            </Link>
                                        </Button>
                                    }
                                />
                            ) : (
                                <EmptyState
                                    size="compact"
                                    icon={UsersGroupTwoRoundedIcon}
                                    title="Composição das comissões"
                                    description="Veja a lista completa, os membros e os documentos vinculados."
                                    action={
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={href('/comissoes')}>
                                                Ver comissões
                                            </Link>
                                        </Button>
                                    }
                                />
                            )}
                        </SectionCard>
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
                    {/* Uma linha só, rolável: cartões pequenos em vez de
                        lista, para caber vários compromissos sem alongar o
                        cartão verticalmente. */}
                    <div className="p-4">
                        <CardCarousel aria-label="Próximos compromissos">
                            {appointments.map((appointment) => (
                                <AppointmentCard
                                    key={`${appointment.id}-${appointment.starts_at}`}
                                    appointment={appointment}
                                    href={href(
                                        `/agenda?view=dia&date=${appointment.date}`,
                                    )}
                                />
                            ))}
                        </CardCarousel>
                    </div>
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
