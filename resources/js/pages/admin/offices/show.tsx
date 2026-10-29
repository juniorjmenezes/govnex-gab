import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SyncStatusDot } from '@/components/admin/sync-progress';
import { BackButton } from '@/components/common/back-button';
import {
    CheckCircleIcon,
    CloseCircleIcon,
    RefreshIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import {
    datasetLabels,
    syncStatusDetail,
    syncStatusLabels,
} from '@/lib/political-sync';
import type { AdminOffice } from '@/types';

type Props = {
    office: AdminOffice;
};

export default function OfficeShow({ office }: Props) {
    const latestSync = office.political_syncs[0];
    const holder = office.office_holder_candidate;
    const holderName =
        holder?.ballot_name || holder?.name || office.councilor_name;
    const holderDetails = [
        holder?.party,
        holder?.number ?? office.candidate_number,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title={office.name} />
            <PageContainer>
                <PageHeader
                    title={office.name}
                    description={`${office.entidade?.name ?? 'Entidade não definida'} · ${office.city}/${office.state}`}
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge
                                variant={
                                    office.status === 'ativo'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {office.status_label}
                            </Badge>
                            <BackButton />
                        </div>
                    }
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle as="h3">Pessoas e acesso</SurfaceTitle>
                    </SurfaceHeader>
                    <dl className="grid gap-4 p-4 sm:grid-cols-2">
                        <OfficeDetail
                            label="Titular"
                            secondary={holderDetails || undefined}
                        >
                            {holderName || 'Não definido'}
                        </OfficeDetail>
                        <OfficeDetail
                            label="Responsável"
                            secondary={office.responsible?.email}
                        >
                            {office.responsible?.name ?? 'Não definido'}
                        </OfficeDetail>
                        <OfficeDetail label="Último acesso">
                            {office.responsible?.last_login_at
                                ? new Date(
                                      office.responsible.last_login_at,
                                  ).toLocaleString('pt-BR')
                                : 'Ainda não acessou'}
                        </OfficeDetail>
                        <OfficeDetail label="Usuários">
                            {office.active_users_count.toLocaleString('pt-BR')}{' '}
                            ativos de{' '}
                            {office.users_count.toLocaleString('pt-BR')}
                        </OfficeDetail>
                    </dl>
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle as="h3">Utilização</SurfaceTitle>
                    </SurfaceHeader>
                    <dl className="grid gap-4 p-4 sm:grid-cols-3">
                        <OfficeDetail label="Cidadãos">
                            {office.citizens_count.toLocaleString('pt-BR')}
                        </OfficeDetail>
                        <OfficeDetail label="Demandas">
                            {office.demands_count.toLocaleString('pt-BR')}
                        </OfficeDetail>
                        <OfficeDetail label="Demandas abertas">
                            {office.open_demands_count.toLocaleString('pt-BR')}
                        </OfficeDetail>
                    </dl>
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader
                        actions={
                            office.modules.includes('POLITICA') ? (
                                <Button variant="outline" asChild>
                                    {/* Os dados do TSE são sincronizados por eleição. */}
                                    <Link href="/admin/eleicoes">
                                        <RefreshIcon aria-hidden="true" />
                                        Dados do TSE
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    >
                        <SurfaceTitle as="h3">Dados políticos</SurfaceTitle>
                    </SurfaceHeader>
                    <dl className="grid gap-4 p-4 sm:grid-cols-2">
                        <OfficeDetail label="Disponibilidade">
                            <PoliticalReadiness office={office} />
                        </OfficeDetail>
                        <OfficeDetail
                            label="Município eleitoral"
                            secondary={
                                office.municipality_linked
                                    ? [
                                          office.municipality_tse_code &&
                                              `TSE ${office.municipality_tse_code}`,
                                          office.municipality_ibge_code &&
                                              `IBGE ${office.municipality_ibge_code}`,
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')
                                    : undefined
                            }
                        >
                            {office.municipality_linked
                                ? 'Vinculado'
                                : 'Não vinculado'}
                        </OfficeDetail>
                        <OfficeDetail
                            label="Eleitorado"
                            secondary={
                                office.electorate_reference_date
                                    ? `Referência: ${new Date(
                                          office.electorate_reference_date,
                                      ).toLocaleDateString('pt-BR')}`
                                    : undefined
                            }
                        >
                            {office.electorate_count !== null
                                ? office.electorate_count.toLocaleString(
                                      'pt-BR',
                                  )
                                : 'Não importado'}
                        </OfficeDetail>
                        <OfficeDetail
                            label="Última sincronização"
                            secondary={
                                latestSync
                                    ? (datasetLabels[latestSync.dataset] ??
                                      latestSync.dataset)
                                    : undefined
                            }
                        >
                            {latestSync ? (
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <SyncStatusDot status={latestSync.status} />
                                    <span className="text-xs font-medium">
                                        {syncStatusLabels[latestSync.status] ??
                                            latestSync.status}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {syncStatusDetail(latestSync)}
                                    </span>
                                </div>
                            ) : (
                                'Nunca sincronizado'
                            )}
                        </OfficeDetail>
                    </dl>
                    <div className="border-t p-4">
                        <p className="mb-3 text-xs text-muted-foreground">
                            O que já foi importado do TSE para este município
                        </p>
                        <ul className="flex flex-wrap gap-x-4 gap-y-3">
                            {office.political_data_checklist
                                .filter((item) => item.key !== 'electorate')
                                .map((item) => (
                                    <li
                                        key={item.key}
                                        className="flex max-w-56 flex-col gap-1"
                                    >
                                        <Badge
                                            variant={
                                                item.available
                                                    ? 'success'
                                                    : 'outline'
                                            }
                                            className="gap-1.5"
                                        >
                                            {item.available ? (
                                                <CheckCircleIcon aria-hidden="true" />
                                            ) : (
                                                <CloseCircleIcon aria-hidden="true" />
                                            )}
                                            {item.label}
                                        </Badge>
                                        {item.note && (
                                            <span className="text-xs leading-snug text-muted-foreground">
                                                {item.note}
                                            </span>
                                        )}
                                    </li>
                                ))}
                        </ul>
                    </div>
                </Surface>
            </PageContainer>
        </>
    );
}

function OfficeDetail({
    label,
    secondary,
    children,
}: {
    label: string;
    secondary?: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-sm font-medium">{children}</dd>
            {secondary && (
                <dd className="text-xs text-muted-foreground">{secondary}</dd>
            )}
        </div>
    );
}

function PoliticalReadiness({ office }: { office: AdminOffice }) {
    if (!office.modules.includes('POLITICA')) {
        return (
            <p className="mt-0.5 text-sm font-medium text-muted-foreground">
                Módulo Política desativado
            </p>
        );
    }

    const hasActiveSync = office.political_syncs.some((sync) =>
        ['pendente', 'processando'].includes(sync.status),
    );
    const hasFailure = office.political_syncs.some(
        (sync) => sync.status === 'falhou',
    );
    const text = hasActiveSync
        ? 'Dados políticos atualizando'
        : hasFailure
          ? 'Falha na sincronização política'
          : office.municipality_linked && office.electorate_count !== null
            ? 'Dados políticos disponíveis'
            : 'Dados políticos pendentes';

    return (
        <p
            className={`mt-0.5 text-sm font-medium ${hasFailure ? 'text-destructive' : 'text-foreground'}`}
        >
            {text}
        </p>
    );
}

OfficeShow.layout = {
    breadcrumbs: [
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        { title: 'Detalhes', href: '#' },
    ],
};
