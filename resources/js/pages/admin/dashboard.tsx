import { Head, Link } from '@inertiajs/react';
import { StatCard } from '@/components/common/stat-card';
import {
    BuildingsIcon,
    CalendarMarkIcon,
    DownloadIcon,
    PulseIcon,
    ShieldWarningIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Surface } from '@/components/ui/surface';
import type { OfficeUsage, PlatformSummary } from '@/types';

type Props = {
    summary: PlatformSummary;
    usage: OfficeUsage[];
};

const number = new Intl.NumberFormat('pt-BR');

export default function PlatformDashboard({ summary, usage }: Props) {
    const activeSharePercent =
        summary.offices > 0
            ? (summary.active_offices / summary.offices) * 100
            : 0;

    const cards = [
        {
            title: 'Gabinetes ativos',
            value: number.format(summary.active_offices),
            progress: {
                percent: activeSharePercent,
                label: `${activeSharePercent.toLocaleString('pt-BR', { maximumFractionDigits: 0 })}% do total`,
                value: number.format(summary.offices),
            },
            icon: BuildingsIcon,
        },
        {
            title: 'Usuários ativos',
            value: number.format(summary.active_users),
            description: 'Contas vinculadas aos gabinetes',
            icon: UsersGroupRoundedIcon,
        },
        {
            title: 'Demandas registradas',
            value: number.format(summary.demands),
            description: `${number.format(summary.demands_last_30_days)} nos últimos 30 dias`,
            icon: PulseIcon,
        },
        {
            title: 'Próximos compromissos',
            value: number.format(summary.appointments_next_30_days),
            description: 'Nos próximos 30 dias',
            icon: CalendarMarkIcon,
        },
        {
            title: 'Exportações recentes',
            value: number.format(summary.exports_last_30_days),
            description: 'Geradas nos últimos 30 dias',
            icon: DownloadIcon,
        },
        {
            title: 'Contas suspensas',
            value: number.format(summary.suspended_offices),
            description: 'Acesso operacional bloqueado',
            icon: ShieldWarningIcon,
        },
    ];

    return (
        <>
            <Head title="Administração da plataforma" />
            <PageContainer>
                <PageHeader
                    title="Visão geral"
                    description="Acompanhe adoção, utilização e situação dos gabinetes sem acessar seus atendimentos operacionais."
                    actions={
                        <Button asChild>
                            <Link href="/admin/gabinetes">
                                Gerenciar gabinetes
                            </Link>
                        </Button>
                    }
                />

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {cards.map((card) => (
                        <StatCard key={card.title} {...card} />
                    ))}
                </section>

                <Surface as="section" className="overflow-hidden">
                    <div className="border-b p-4">
                        <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                            Utilização por gabinete
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Os dez gabinetes com mais demandas registradas.
                        </p>
                    </div>
                    {usage.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            Nenhum gabinete cadastrado.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-3 py-3 font-medium first:px-5 last:px-5">
                                            Gabinete
                                        </th>
                                        <th className="px-3 py-3 font-medium first:px-5 last:px-5">
                                            Situação
                                        </th>
                                        <th className="px-3 py-3 text-right font-medium first:px-5 last:px-5">
                                            Usuários
                                        </th>
                                        <th className="px-3 py-3 text-right font-medium first:px-5 last:px-5">
                                            Cidadãos
                                        </th>
                                        <th className="px-3 py-3 text-right font-medium first:px-5 last:px-5">
                                            Demandas
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {usage.map((office) => (
                                        <tr key={office.id}>
                                            <td className="px-3 py-3 font-normal first:px-5 last:px-5">
                                                <span className="font-normal">
                                                    {office.name}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {office.city}/{office.state}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3 font-normal first:px-5 last:px-5">
                                                <Badge
                                                    variant={
                                                        office.status ===
                                                        'ativo'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {office.status_label}
                                                </Badge>
                                            </td>
                                            <td className="px-3 py-3 text-right font-normal tabular-nums first:px-5 last:px-5">
                                                {number.format(office.users)}
                                            </td>
                                            <td className="px-3 py-3 text-right font-normal tabular-nums first:px-5 last:px-5">
                                                {number.format(office.citizens)}
                                            </td>
                                            <td className="px-3 py-3 text-right font-normal tabular-nums first:px-5 last:px-5">
                                                {number.format(office.demands)}
                                                <span className="block text-xs text-muted-foreground">
                                                    {number.format(
                                                        office.open_demands,
                                                    )}{' '}
                                                    abertas
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

PlatformDashboard.layout = {
    breadcrumbs: [{ title: 'Administração', href: '/dashboard' }],
};
