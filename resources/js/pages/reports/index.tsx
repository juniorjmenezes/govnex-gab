import { Head, Link, router } from '@inertiajs/react';
import {
    ChartIcon,
    CheckCircleIcon,
    ClockCircleIcon,
    CloseIcon,
    DangerTriangleIcon,
    DocumentTextIcon,
    DownloadIcon,
    PresentationGraphIcon,
    RefreshIcon,
} from '@solar-icons/react/outline';
import { format, formatDistanceToNow } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useEffect, useRef, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { PaginationLinks } from '@/components/common/pagination-links';
import { StatCard } from '@/components/common/stat-card';
import { TableActionButton } from '@/components/common/table-action-button';
import { PriorityBadge } from '@/components/demands/priority-badge';
import { StatusBadge } from '@/components/demands/status-badge';
import { EmptyState } from '@/components/feedback/empty-state';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type { DashboardDatum, ReportExport, ReportPageProps } from '@/types';

const formatDate = (value: string | null) =>
    value ? format(new Date(value), 'dd/MM/yyyy') : '—';

const formatAverage = (hours: number | null) => {
    if (hours === null) {
        return '—';
    }

    if (hours < 24) {
        return `${Math.round(hours)}h`;
    }

    return `${(hours / 24).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}d`;
};

const formatBytes = (bytes: number | null) => {
    if (bytes === null) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const exportVariant: Record<
    ReportExport['status'],
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pendente: 'outline',
    processando: 'secondary',
    concluido: 'default',
    falhou: 'destructive',
    cancelado: 'outline',
};

function DistributionChart({ data }: { data: DashboardDatum[] }) {
    const visible = data.filter((item) => item.total > 0);

    if (visible.length === 0) {
        return (
            <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                Sem dados para os filtros selecionados.
            </div>
        );
    }

    return (
        <div
            className="h-64"
            aria-label="Gráfico de área da distribuição do relatório"
        >
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                    data={visible}
                    margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
                    accessibilityLayer
                >
                    <defs>
                        <linearGradient
                            id="distributionArea"
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
                    <CartesianGrid strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="label" hide />
                    <YAxis
                        allowDecimals={false}
                        axisLine={false}
                        tickLine={false}
                        tick={{ fontSize: 11 }}
                        width={28}
                    />
                    <Tooltip
                        cursor={{ stroke: 'var(--border)' }}
                        content={({ active, payload }) => {
                            const datum = payload?.[0]?.payload as
                                DashboardDatum | undefined;

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
                                        <span className="ml-auto pl-4 font-mono font-medium tabular-nums">
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
                        fill="url(#distributionArea)"
                        dot={{
                            r: 3,
                            fill: 'var(--background)',
                            strokeWidth: 2,
                        }}
                        activeDot={{ r: 5, strokeWidth: 2 }}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}
export default function ReportsIndex({
    filters,
    summary,
    charts,
    productivity,
    waitingReferrals,
    demands,
    options,
    exports,
}: ReportPageProps) {
    const tenantUrl = useTenantUrl();
    const [form, setForm] = useState({
        ...filters,
        categoria_id: filters.categoria_id?.toString() ?? '',
        bairro_id: filters.bairro_id?.toString() ?? '',
        responsavel_id: filters.responsavel_id?.toString() ?? '',
        status: filters.status ?? '',
        prioridade: filters.prioridade ?? '',
        origem: filters.origem ?? '',
    });
    const [distribution, setDistribution] = useState<
        | 'status'
        | 'category'
        | 'neighborhood'
        | 'responsible'
        | 'origin'
        | 'priority'
    >('status');
    const distributionOptions = [
        { key: 'status' as const, label: 'Status' },
        { key: 'category' as const, label: 'Categoria' },
        { key: 'neighborhood' as const, label: 'Bairro' },
        { key: 'responsible' as const, label: 'Responsável' },
        { key: 'origin' as const, label: 'Origem' },
        { key: 'priority' as const, label: 'Prioridade' },
    ];

    const queryData = () => ({
        inicio: form.inicio,
        fim: form.fim,
        status: form.status || undefined,
        prioridade: form.prioridade || undefined,
        origem: form.origem || undefined,
        categoria_id: form.categoria_id || undefined,
        bairro_id: form.bairro_id || undefined,
        responsavel_id: form.responsavel_id || undefined,
        atrasadas: form.atrasadas ? 1 : undefined,
    });

    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(tenantUrl('/relatorios'), queryData(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 400);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        form.inicio,
        form.fim,
        form.status,
        form.prioridade,
        form.origem,
        form.categoria_id,
        form.bairro_id,
        form.responsavel_id,
        form.atrasadas,
    ]);

    const hasFilters = Boolean(
        form.status ||
        form.prioridade ||
        form.origem ||
        form.categoria_id ||
        form.bairro_id ||
        form.responsavel_id ||
        form.atrasadas,
    );

    const reset = () => router.get(tenantUrl('/relatorios'));

    const requestExport = (formatType: 'pdf' | 'xlsx') => {
        router.post(
            tenantUrl('/relatorios/exportacoes'),
            { ...queryData(), formato: formatType },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Relatórios" />
            <PageContainer>
                <PageHeader
                    title="Relatórios"
                    description="Analise o atendimento do gabinete e gere arquivos privados com os mesmos filtros exibidos na tela."
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="outline"

                                onClick={() => requestExport('pdf')}
                            >
                                <PresentationGraphIcon aria-hidden="true" />
                                Exportar PDF
                            </Button>
                            <Button
                                type="button"

                                onClick={() => requestExport('xlsx')}
                            >
                                <DocumentTextIcon aria-hidden="true" />
                                Exportar XLSX
                            </Button>
                        </>
                    }
                />

                <form
                    onSubmit={(event) => event.preventDefault()}
                    className={cn(surfaceClasses, 'overflow-hidden')}
                    aria-label="Filtros do relatório"
                >
                    <div className="border-b p-4">
                        <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                            Filtros do relatório
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            O período considera a data de abertura da demanda.
                        </p>
                    </div>
                    <div className="p-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Label className="grid gap-1">
                                Início
                                <Input
                                    type="date"
                                    value={form.inicio}
                                    onChange={(event) =>
                                        setForm((current) => ({
                                            ...current,
                                            inicio: event.target.value,
                                        }))
                                    }
                                />
                            </Label>
                            <Label className="grid gap-1">
                                Fim
                                <Input
                                    type="date"
                                    value={form.fim}
                                    onChange={(event) =>
                                        setForm((current) => ({
                                            ...current,
                                            fim: event.target.value,
                                        }))
                                    }
                                />
                            </Label>
                            <FilterSelect
                                label="Status"
                                value={form.status}
                                onChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        status: value,
                                    }))
                                }
                                options={options.statuses}
                            />
                            <FilterSelect
                                label="Prioridade"
                                value={form.prioridade}
                                onChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        prioridade: value,
                                    }))
                                }
                                options={options.priorities}
                            />
                            <FilterSelect
                                label="Origem"
                                value={form.origem}
                                onChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        origem: value,
                                    }))
                                }
                                options={options.origins}
                            />
                            <FilterSelect
                                label="Categoria"
                                value={form.categoria_id}
                                onChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        categoria_id: value,
                                    }))
                                }
                                options={options.categories.map((item) => ({
                                    value: String(item.id),
                                    label: item.nome,
                                }))}
                            />
                            <FilterSelect
                                label="Bairro"
                                value={form.bairro_id}
                                onChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        bairro_id: value,
                                    }))
                                }
                                options={options.neighborhoods.map((item) => ({
                                    value: String(item.id),
                                    label: item.nome,
                                }))}
                            />
                            <FilterSelect
                                label="Responsável"
                                value={form.responsavel_id}
                                onChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        responsavel_id: value,
                                    }))
                                }
                                options={options.members.map((item) => ({
                                    value: String(item.id),
                                    label: item.name,
                                }))}
                            />
                        </div>
                        <div className="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <Label>
                                <Switch
                                    checked={form.atrasadas}
                                    onCheckedChange={(checked) =>
                                        setForm((current) => ({
                                            ...current,
                                            atrasadas: checked,
                                        }))
                                    }
                                />
                                Somente demandas atrasadas
                            </Label>
                            {hasFilters && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={reset}
                                    aria-label="Limpar filtros"
                                >
                                    <CloseIcon aria-hidden="true" />
                                </Button>
                            )}
                        </div>
                    </div>
                </form>

                <section
                    aria-label="Resumo do relatório"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <StatCard
                        title="Demandas no período"
                        value={summary.total}
                        description={`${formatDate(filters.inicio)} a ${formatDate(filters.fim)}`}
                        icon={ChartIcon}
                    />
                    <StatCard
                        title="Resolvidas"
                        value={summary.resolved}
                        progress={{
                            percent: summary.resolution_rate,
                            label: `${summary.resolution_rate.toLocaleString('pt-BR')}% de resolução`,
                            value: summary.total,
                        }}
                        icon={CheckCircleIcon}
                    />
                    <StatCard
                        title="Atrasadas"
                        value={summary.overdue}
                        description="Demandas abertas fora do prazo"
                        icon={DangerTriangleIcon}
                        valueClassName={
                            summary.overdue > 0 ? 'text-destructive' : undefined
                        }
                    />
                    <StatCard
                        title="Tempo médio"
                        value={formatAverage(summary.average_resolution_hours)}
                        description="Da abertura à conclusão"
                        icon={ClockCircleIcon}
                    />
                </section>

                <section className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-4">
                    {[
                        ['Abertas', summary.open],
                        ['Encerradas', summary.closed],
                        [
                            'Encaminhamentos aguardando retorno',
                            summary.waiting_referrals,
                        ],
                        [
                            'Taxa de resolução',
                            `${summary.resolution_rate.toLocaleString('pt-BR')}%`,
                        ],
                    ].map(([label, value]) => (
                        <div key={label} className="bg-card px-4 py-3">
                            <p className="text-xl font-semibold tabular-nums">
                                {value}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {label}
                            </p>
                        </div>
                    ))}
                </section>

                <Surface as="section" className="overflow-hidden">
                    <div className="border-b p-4">
                        <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                            Demandas do relatório
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {summary.total} registro
                            {summary.total === 1 ? '' : 's'} encontrado
                            {summary.total === 1 ? '' : 's'}
                        </p>
                    </div>
                    {demands.data.length === 0 ? (
                        <EmptyState
                            icon={ChartIcon}
                            title="Nenhuma demanda encontrada"
                            description="Revise o período ou remova alguns filtros."
                        />
                    ) : (
                        <>
                            <div className="hidden lg:block">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Demanda</TableHead>
                                            <TableHead>Prioridade</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Responsável</TableHead>
                                            <TableHead>Abertura</TableHead>
                                            <TableHead>Prazo</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {demands.data.map((demand) => (
                                            <TableRow key={demand.id}>
                                                <TableCell>
                                                    <Link
                                                        href={tenantUrl(
                                                            `/demandas/${demand.id}`,
                                                        )}
                                                        className="font-normal hover:underline"
                                                    >
                                                        <span className="font-mono">
                                                            {demand.protocol}
                                                        </span>{' '}
                                                        · {demand.title}
                                                    </Link>
                                                    <p className="text-xs text-muted-foreground">
                                                        {demand.citizen ??
                                                            'Cidadão não informado'}
                                                    </p>
                                                </TableCell>
                                                <TableCell>
                                                    <PriorityBadge
                                                        priority={
                                                            demand.priority
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={demand.status}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {demand.responsible ??
                                                        'Não atribuído'}
                                                </TableCell>
                                                <TableCell>
                                                    {formatDate(
                                                        demand.opened_at,
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={
                                                        demand.overdue
                                                            ? 'font-normal text-destructive'
                                                            : ''
                                                    }
                                                >
                                                    {formatDate(
                                                        demand.deadline,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <div className="divide-y lg:hidden">
                                {demands.data.map((demand) => (
                                    <Link
                                        key={demand.id}
                                        href={tenantUrl(
                                            `/demandas/${demand.id}`,
                                        )}
                                        className="block space-y-3 px-5 py-4 hover:bg-muted/40"
                                    >
                                        <div>
                                            <p className="font-mono text-sm text-muted-foreground">
                                                {demand.protocol}
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {demand.title}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <PriorityBadge
                                                priority={demand.priority}
                                            />
                                            <StatusBadge
                                                status={demand.status}
                                            />
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {demand.responsible ??
                                                'Não atribuído'}{' '}
                                            · prazo{' '}
                                            {formatDate(demand.deadline)}
                                        </p>
                                    </Link>
                                ))}
                            </div>
                            <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                                Exibindo {demands.from}–{demands.to} de{' '}
                                {demands.total} demanda(s)
                            </div>
                            <PaginationLinks links={demands.links} />
                        </>
                    )}
                </Surface>

                <div className="grid gap-6">
                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Evolução mensal
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Demandas abertas no período
                            </p>
                        </div>
                        <div
                            className="h-72 p-4"
                            aria-label="Gráfico da evolução mensal"
                        >
                            <ResponsiveContainer width="100%" height="100%">
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
                                            id="monthlyArea"
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
                                        cursor={{ stroke: 'var(--border)' }}
                                        content={({ active, payload }) => {
                                            const datum = payload?.[0]
                                                ?.payload as
                                                DashboardDatum | undefined;

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
                                                        <span className="ml-auto pl-4 font-mono font-medium tabular-nums">
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
                                        fill="url(#monthlyArea)"
                                        dot={{
                                            r: 3,
                                            fill: 'var(--background)',
                                            strokeWidth: 2,
                                        }}
                                        activeDot={{ r: 5, strokeWidth: 2 }}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </Surface>

                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Distribuição
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Demandas por{' '}
                                {distributionOptions
                                    .find(
                                        (option) => option.key === distribution,
                                    )
                                    ?.label.toLowerCase()}
                            </p>
                        </div>
                        <div className="p-4">
                            <div
                                className="mb-4 flex flex-wrap gap-1"
                                aria-label="Dimensão do gráfico"
                            >
                                {distributionOptions.map((option) => (
                                    <Button
                                        key={option.key}
                                        type="button"
                                        size="sm"
                                        variant={
                                            distribution === option.key
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        onClick={() =>
                                            setDistribution(option.key)
                                        }
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                            <DistributionChart data={charts[distribution]} />
                        </div>
                    </Surface>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Produtividade da equipe
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Volume atribuído e resolvido no período
                            </p>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Responsável</TableHead>
                                    <TableHead className="text-right">
                                        Atribuídas
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Resolvidas
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Tempo médio
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {productivity.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="h-28 text-center text-muted-foreground"
                                        >
                                            Sem dados no período.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    productivity.map((member) => (
                                        <TableRow
                                            key={member.id ?? 'unassigned'}
                                        >
                                            <TableCell className="font-normal">
                                                {member.name}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {member.assigned}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {member.resolved}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatAverage(
                                                    member.average_resolution_hours,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Surface>

                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Encaminhamentos aguardando
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Ordenados pelo prazo de resposta
                            </p>
                        </div>
                        {waitingReferrals.length === 0 ? (
                            <div className="px-5 py-12 text-center text-sm text-muted-foreground">
                                Nenhum encaminhamento aguardando resposta.
                            </div>
                        ) : (
                            <div className="divide-y">
                                {waitingReferrals
                                    .slice(0, 8)
                                    .map((referral) => (
                                        <Link
                                            key={referral.id}
                                            href={tenantUrl(
                                                `/demandas/${referral.demand?.id}`,
                                            )}
                                            className="flex items-start justify-between gap-4 px-5 py-4 hover:bg-muted/40"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {referral.recipient}
                                                </p>
                                                <p className="mt-1 truncate text-xs text-muted-foreground">
                                                    <span className="font-mono">
                                                        {
                                                            referral.demand
                                                                ?.protocol
                                                        }
                                                    </span>{' '}
                                                    · {referral.demand?.title}
                                                </p>
                                            </div>
                                            <span
                                                className={
                                                    referral.overdue
                                                        ? 'shrink-0 text-xs font-medium text-destructive'
                                                        : 'shrink-0 text-xs text-muted-foreground'
                                                }
                                            >
                                                {formatDate(referral.deadline)}
                                            </span>
                                        </Link>
                                    ))}
                            </div>
                        )}
                    </Surface>
                </div>

                <Surface as="section" className="overflow-hidden">
                    <div className="flex items-center justify-between gap-4 border-b p-4">
                        <div>
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Exportações recentes
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Arquivos privados expiram sete dias após a
                                geração
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.reload({
                                    only: ['exports'],
                                })
                            }
                        >
                            <RefreshIcon aria-hidden="true" />
                            Atualizar
                        </Button>
                    </div>
                    {exports.length === 0 ? (
                        <div className="px-5 py-12 text-center text-sm text-muted-foreground">
                            Nenhuma exportação solicitada.
                        </div>
                    ) : (
                        <div className="divide-y">
                            {exports.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">
                                                Relatório {item.format_label}
                                            </span>
                                            <Badge
                                                variant={
                                                    exportVariant[item.status]
                                                }
                                            >
                                                {item.status_label}
                                            </Badge>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Solicitado{' '}
                                            {formatDistanceToNow(
                                                new Date(item.created_at),
                                                {
                                                    addSuffix: true,
                                                    locale: ptBR,
                                                },
                                            )}
                                            {item.requested_by
                                                ? ` por ${item.requested_by}`
                                                : ''}
                                            {item.size
                                                ? ` · ${formatBytes(item.size)}`
                                                : ''}
                                        </p>
                                        {item.error && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {item.error}
                                            </p>
                                        )}
                                    </div>
                                    {item.downloadable ? (
                                        <TableActionButton
                                            asChild
                                            variant="outline"
                                            label={`Baixar ${item.format_label}`}
                                        >
                                            <a
                                                href={tenantUrl(
                                                    `/relatorios/exportacoes/${item.id}/download`,
                                                )}
                                            >
                                                <DownloadIcon aria-hidden="true" />
                                            </a>
                                        </TableActionButton>
                                    ) : item.status === 'processando' ||
                                      item.status === 'pendente' ? (
                                        <span className="text-xs text-muted-foreground">
                                            Processamento em fila
                                        </span>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
}) {
    return (
        <Label className="grid gap-1">
            {label}
            <AppSelect
                value={value}
                onValueChange={onChange}
                options={options}
                emptyLabel="Todos"
            />
        </Label>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Relatórios',
            href: '/relatorios',
        },
    ],
};
