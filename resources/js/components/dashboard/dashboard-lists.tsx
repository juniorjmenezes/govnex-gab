import { Link } from '@inertiajs/react';

import { PriorityBadge } from '@/components/demands/priority-badge';
import { StatusBadge } from '@/components/demands/status-badge';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { DashboardAppointment, DashboardDemand } from '@/types';

import { describeDeadline, formatShortDate } from './dashboard-format';
import type { DeadlineTone } from './dashboard-format';

const rowLink =
    'group flex min-w-0 items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset';

const toneVariant: Record<DeadlineTone, 'danger' | 'warning' | 'outline'> = {
    danger: 'danger',
    warning: 'warning',
    neutral: 'outline',
};

export function DeadlineBadge({ deadline }: { deadline: string | null }) {
    const info = describeDeadline(deadline);

    if (!info) {
        return <span className="text-xs text-muted-foreground">Sem prazo</span>;
    }

    return (
        <Badge
            variant={toneVariant[info.tone]}
            className={cn(info.tone === 'neutral' && 'text-muted-foreground')}
            title={deadline ? formatShortDate(deadline) : undefined}
        >
            {info.label}
        </Badge>
    );
}

/**
 * Linha de demanda em lista compacta (atenção imediata, próximos prazos):
 * título em destaque, protocolo/prioridade/responsável como meta e o prazo
 * relativo com badge semântico à direita.
 */
export function DemandListItem({
    demand,
    href,
}: {
    demand: DashboardDemand;
    href: string;
}) {
    return (
        <li>
            <Link href={href} className={rowLink}>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <p
                        className="truncate text-sm font-medium text-foreground"
                        title={demand.title}
                    >
                        {demand.title}
                    </p>
                    <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                        <span className="shrink-0 tabular-nums">
                            {demand.protocol}
                        </span>
                        <PriorityBadge priority={demand.priority} />
                        <span className="truncate">
                            {demand.responsible?.name ?? 'Sem responsável'}
                        </span>
                    </div>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <DeadlineBadge deadline={demand.deadline} />
                    {demand.deadline && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {formatShortDate(demand.deadline)}
                        </span>
                    )}
                </div>
            </Link>
        </li>
    );
}

export function RecentDemandsTable({
    demands,
    hrefFor,
}: {
    demands: DashboardDemand[];
    hrefFor: (demand: DashboardDemand) => string;
}) {
    return (
        // Colunas somem pela largura do próprio cartão (container query), não da
        // viewport: o cartão muda de largura com a sidebar e com a grade.
        <div className="@container">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="hidden w-32 @xl:table-cell">
                            Código
                        </TableHead>
                        <TableHead>Assunto</TableHead>
                        <TableHead className="hidden @2xl:table-cell">
                            Responsável
                        </TableHead>
                        <TableHead>Situação</TableHead>
                        <TableHead className="hidden text-right @lg:table-cell">
                            Prazo
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {demands.map((demand) => (
                        <TableRow key={demand.id}>
                            <TableCell className="hidden text-muted-foreground tabular-nums @xl:table-cell">
                                {demand.protocol}
                            </TableCell>
                            <TableCell className="w-full max-w-0">
                                <Link
                                    href={hrefFor(demand)}
                                    className="block truncate rounded-sm font-medium text-foreground hover:text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    title={demand.title}
                                >
                                    {demand.title}
                                </Link>
                                <p className="truncate text-xs text-muted-foreground">
                                    <span className="tabular-nums @xl:hidden">
                                        {demand.protocol} ·{' '}
                                    </span>
                                    {demand.citizen?.name ??
                                        'Cidadão não informado'}
                                    {demand.category
                                        ? ` · ${demand.category.name}`
                                        : ''}
                                </p>
                            </TableCell>
                            <TableCell className="hidden text-muted-foreground @2xl:table-cell">
                                {demand.responsible?.name ?? 'Sem responsável'}
                            </TableCell>
                            <TableCell>
                                <StatusBadge
                                    status={demand.status}
                                    tone="soft"
                                />
                            </TableCell>
                            <TableCell className="hidden text-right text-muted-foreground tabular-nums @lg:table-cell">
                                {demand.deadline ? (
                                    <span
                                        className={cn(
                                            demand.overdue &&
                                                'font-medium text-destructive',
                                        )}
                                    >
                                        {formatShortDate(demand.deadline)}
                                        {demand.overdue && (
                                            <span className="sr-only">
                                                {' '}
                                                (vencido)
                                            </span>
                                        )}
                                    </span>
                                ) : (
                                    '—'
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Compromisso em cartão pequeno para o carrossel de "Próximos compromissos":
 * horário em destaque (não uma data em bloco, tipo calendário) — a mesma
 * ideia de uma agenda impressa, não de item de lista de notícias. A situação
 * já é sinalizada pelo badge, sem precisar de uma faixa de cor extra.
 */
export function AppointmentCard({
    appointment,
    href,
}: {
    appointment: DashboardAppointment;
    href: string;
}) {
    const today = appointment.date_label === 'Hoje';
    const confirmed = appointment.status === 'confirmado';
    const place = appointment.location ?? appointment.responsible?.name;

    return (
        <Link
            href={href}
            className="flex w-56 shrink-0 snap-start flex-col gap-2 rounded-lg border border-border bg-muted/40 p-3 transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 flex-col">
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            today && 'text-primary',
                        )}
                    >
                        {appointment.time_label}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {appointment.date_label}
                    </span>
                </div>
                <Badge
                    variant={confirmed ? 'success' : 'outline'}
                    className={cn(
                        'shrink-0',
                        !confirmed && 'text-muted-foreground',
                    )}
                >
                    {appointment.status_label}
                </Badge>
            </div>
            <div className="min-w-0">
                <p
                    className="line-clamp-2 text-sm font-medium text-foreground"
                    title={appointment.title}
                >
                    {appointment.title}
                </p>
                {place && (
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {place}
                    </p>
                )}
            </div>
        </Link>
    );
}
