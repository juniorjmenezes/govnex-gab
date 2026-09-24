import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import {
    getOfficeModuleSelectionErrors,
    OfficeModuleSelector,
    toggleOfficeModule,
} from '@/components/admin/office-module-selector';
import { SyncStatusDot } from '@/components/admin/sync-progress';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import {
    AddIcon,
    BuildingsIcon,
    CheckCircleIcon,
    CloseCircleIcon,
    CloseIcon,
    EyeIcon,
    MagnifierIcon,
    PenIcon,
    PowerIcon,
    RefreshIcon,
    SettingsIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Surface,
    surfaceClasses,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { preservedListParams } from '@/lib/pagination';
import {
    datasetLabels,
    syncStatusDetail,
    syncStatusLabels,
} from '@/lib/political-sync';
import { cn } from '@/lib/utils';
import type {
    AdminOffice,
    GabineteModuleCode,
    GabineteModuleDefinition,
    OfficePagination,
} from '@/types';

type Option = { value: string; label: string };
type Filters = { q: string; status: string; estado: string };
type Props = {
    offices: OfficePagination;
    filters: Filters;
    statuses: Option[];
    moduleCatalog: GabineteModuleDefinition[];
};

const statusActionLabel = (office: AdminOffice) =>
    office.hub_linked
        ? `Situação de ${office.name}: definida no Govnex Hub — altere lá`
        : `${office.status === 'ativo' ? 'Suspender' : 'Reativar'} ${office.name}`;

export default function Offices({
    offices,
    filters,
    statuses,
    moduleCatalog,
}: Props) {
    const [statusTarget, setStatusTarget] = useState<AdminOffice | null>(null);
    const [detailsTargetId, setDetailsTargetId] = useState<number | null>(null);
    const detailsTarget =
        offices.data.find((office) => office.id === detailsTargetId) ?? null;
    const [modulesTargetId, setModulesTargetId] = useState<number | null>(null);
    const modulesTarget =
        offices.data.find((office) => office.id === modulesTargetId) ?? null;
    const [selectedModules, setSelectedModules] = useState<
        GabineteModuleCode[]
    >([]);
    const [query, setQuery] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [state, setState] = useState(filters.estado);

    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                '/admin/gabinetes',
                {
                    q: query,
                    status,
                    estado: state,
                    ...preservedListParams(),
                },
                { preserveState: true, replace: true },
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [query, status, state]);

    // Enquanto houver algum processamento pendente, atualiza os dados para que o
    // status visível nesta lista reflita o progresso real sem precisar de
    // um F5 manual.
    const hasActiveSyncs = offices.data.some((office) =>
        office.political_syncs.some((sync) =>
            ['pendente', 'processando'].includes(sync.status),
        ),
    );

    useEffect(() => {
        if (!hasActiveSyncs) {
            return;
        }

        const { stop } = router.poll(4000, {
            only: ['offices'],
        });

        return stop;
    }, [hasActiveSyncs]);

    const hasFilters = Boolean(query || status || state);
    const clearFilters = () => {
        setQuery('');
        setStatus('');
        setState('');
        router.get('/admin/gabinetes', {}, { replace: true });
    };

    const openCreate = () => {
        router.get('/admin/gabinetes/novo');
    };
    const openEdit = (office: AdminOffice) => {
        router.get(`/admin/gabinetes/${office.id}/editar`);
    };
    const openModules = (office: AdminOffice) => {
        setModulesTargetId(office.id);
        setSelectedModules(office.modules);
    };
    const moduleErrors = getOfficeModuleSelectionErrors(
        selectedModules,
        moduleCatalog,
    );
    const saveModules = () => {
        if (!modulesTarget || moduleErrors.length > 0) {
            return;
        }

        router.patch(
            `/admin/gabinetes/${modulesTarget.id}/modulos`,
            { modules: selectedModules },
            {
                preserveScroll: true,
                onSuccess: () => setModulesTargetId(null),
            },
        );
    };
    const changeStatus = () => {
        if (!statusTarget) {
            return;
        }

        const next = statusTarget.status === 'ativo' ? 'suspenso' : 'ativo';
        router.patch(
            `/admin/gabinetes/${statusTarget.id}/status`,
            { status: next },
            {
                preserveScroll: true,
                onSuccess: () => setStatusTarget(null),
            },
        );
    };

    return (
        <>
            <Head title="Gabinetes" />
            <PageContainer>
                <PageHeader
                    title="Gabinetes"
                    description="Gerencie os gabinetes das entidades, suas contas responsáveis, módulos e situação de acesso."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/admin/entidades/nova">
                                    <AddIcon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Nova entidade
                                </Link>
                            </Button>
                            <Button onClick={openCreate}>
                                <AddIcon
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Novo gabinete
                            </Button>
                        </div>
                    }
                />

                <form
                    onSubmit={(event) => event.preventDefault()}
                    className={cn(
                        surfaceClasses,
                        'flex flex-wrap items-center gap-3 p-4',
                    )}
                >
                    <div className="relative min-w-56 flex-1">
                        <MagnifierIcon
                            className="absolute top-2.5 left-3 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Gabinete, parlamentar ou cidade"
                            className="pl-9"
                        />
                    </div>
                    <AppSelect
                        value={status}
                        onValueChange={setStatus}
                        emptyLabel="Todas as situações"
                        options={statuses}
                        aria-label="Filtrar por situação"
                        className="w-52"
                    />
                    <Input
                        value={state}
                        onChange={(event) =>
                            setState(event.target.value.toUpperCase())
                        }
                        maxLength={2}
                        placeholder="UF"
                        className="w-24 uppercase"
                    />
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={clearFilters}
                            aria-label="Limpar filtros"
                        >
                            <CloseIcon />
                        </Button>
                    )}
                </form>

                <Surface as="section" className="overflow-hidden">
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-3 py-3 font-medium first:px-5 last:px-5">
                                        Gabinete
                                    </th>
                                    <th className="px-3 py-3 font-medium first:px-5 last:px-5">
                                        Responsável
                                    </th>
                                    <th className="px-3 py-3 text-right font-medium first:px-5 last:px-5">
                                        Demandas
                                    </th>
                                    <th className="px-3 py-3 font-medium first:px-5 last:px-5">
                                        Situação
                                    </th>
                                    <th className="px-3 py-3 text-right font-medium first:px-5 last:px-5">
                                        Ações
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {offices.data.map((office) => (
                                    <tr key={office.id}>
                                        <td className="px-3 py-3 font-normal first:px-5 last:px-5">
                                            <p className="font-normal">
                                                {office.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {office.entidade?.name ??
                                                    'Entidade não definida'}
                                                {' · '}
                                                {office.city}/{office.state}
                                            </p>
                                        </td>
                                        <td className="px-3 py-3 font-normal first:px-5 last:px-5">
                                            <p>
                                                {office.responsible?.name ??
                                                    'Não definido'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {office.responsible?.email ??
                                                    '—'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-3 text-right font-normal tabular-nums first:px-5 last:px-5">
                                            {office.demands_count.toLocaleString(
                                                'pt-BR',
                                            )}{' '}
                                            demandas
                                        </td>
                                        <td className="px-3 py-3 font-normal first:px-5 last:px-5">
                                            <Badge
                                                variant={
                                                    office.status === 'ativo'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {office.status_label}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-3 font-normal first:px-5 last:px-5">
                                            <div className="flex justify-end gap-2">
                                                <TableActionButton
                                                    variant="outline"
                                                    label={`Ver detalhes de ${office.name}`}
                                                    onClick={() =>
                                                        setDetailsTargetId(
                                                            office.id,
                                                        )
                                                    }
                                                >
                                                    <EyeIcon aria-hidden="true" />
                                                </TableActionButton>
                                                <TableActionButton
                                                    variant="outline"
                                                    label={`Configurar módulos de ${office.name}`}
                                                    onClick={() =>
                                                        openModules(office)
                                                    }
                                                >
                                                    <SettingsIcon aria-hidden="true" />
                                                </TableActionButton>
                                                <TableActionButton
                                                    variant="outline"
                                                    label={`Editar ${office.name}`}
                                                    onClick={() =>
                                                        openEdit(office)
                                                    }
                                                >
                                                    <PenIcon aria-hidden="true" />
                                                </TableActionButton>
                                                <TableActionButton
                                                    variant={
                                                        office.status ===
                                                        'ativo'
                                                            ? 'destructive'
                                                            : 'outline'
                                                    }
                                                    label={statusActionLabel(
                                                        office,
                                                    )}
                                                    disabled={office.hub_linked}
                                                    onClick={() =>
                                                        setStatusTarget(office)
                                                    }
                                                >
                                                    <PowerIcon aria-hidden="true" />
                                                </TableActionButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="divide-y md:hidden">
                        {offices.data.map((office) => (
                            <article key={office.id} className="space-y-4 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2
                                            className="truncate text-sm font-semibold"
                                            title={office.name}
                                        >
                                            {office.name}
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            {office.entidade?.name ??
                                                'Entidade não definida'}
                                            {' · '}
                                            {office.city}/{office.state}
                                        </p>
                                    </div>
                                    <Badge
                                        variant={
                                            office.status === 'ativo'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {office.status_label}
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Responsável:{' '}
                                    {office.responsible?.name ?? 'não definido'}
                                </p>
                                <div className="flex items-center justify-between gap-3 text-sm">
                                    <span>
                                        {office.demands_count.toLocaleString(
                                            'pt-BR',
                                        )}{' '}
                                        demandas
                                    </span>
                                </div>
                                <div className="flex justify-end gap-2">
                                    <TableActionButton
                                        variant="outline"
                                        label={`Ver detalhes de ${office.name}`}
                                        onClick={() =>
                                            setDetailsTargetId(office.id)
                                        }
                                    >
                                        <EyeIcon aria-hidden="true" />
                                    </TableActionButton>
                                    <TableActionButton
                                        variant="outline"
                                        label={`Configurar módulos de ${office.name}`}
                                        onClick={() => openModules(office)}
                                    >
                                        <SettingsIcon aria-hidden="true" />
                                    </TableActionButton>
                                    <TableActionButton
                                        variant="outline"
                                        label={`Editar ${office.name}`}
                                        onClick={() => openEdit(office)}
                                    >
                                        <PenIcon aria-hidden="true" />
                                    </TableActionButton>
                                    <TableActionButton
                                        variant={
                                            office.status === 'ativo'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                        label={statusActionLabel(office)}
                                        disabled={office.hub_linked}
                                        onClick={() => setStatusTarget(office)}
                                    >
                                        <PowerIcon aria-hidden="true" />
                                    </TableActionButton>
                                </div>
                            </article>
                        ))}
                    </div>
                    {offices.data.length === 0 ? (
                        <div className="p-10 text-center">
                            <BuildingsIcon className="mx-auto size-8 text-muted-foreground" />
                            <p className="mt-3 font-medium">
                                Nenhum gabinete encontrado
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Revise os filtros ou faça um novo cadastro.
                            </p>
                        </div>
                    ) : (
                        <>
                            <PaginationLinks
                                pagination={offices}
                                label="gabinete(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>

            <OfficeDetailsDialog
                office={detailsTarget}
                onClose={() => setDetailsTargetId(null)}
            />

            <Dialog
                open={modulesTarget !== null}
                onOpenChange={(open) => !open && setModulesTargetId(null)}
            >
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Configurar módulos</DialogTitle>
                        <DialogDescription>
                            Defina as áreas disponíveis para{' '}
                            {modulesTarget?.name}. Os dados dos módulos
                            desativados serão preservados.
                        </DialogDescription>
                    </DialogHeader>
                    <OfficeModuleSelector
                        catalog={moduleCatalog}
                        selected={selectedModules}
                        errors={moduleErrors}
                        idPrefix="office-module"
                        onToggle={(module, checked) =>
                            setSelectedModules((current) =>
                                toggleOfficeModule(current, module, checked),
                            )
                        }
                    />
                    {modulesTarget &&
                        modulesTarget.module_history.length > 0 && (
                            <div className="space-y-2 border-t pt-4">
                                <h3 className="text-sm font-medium">
                                    Alterações recentes
                                </h3>
                                <ul className="space-y-1 text-sm text-muted-foreground">
                                    {modulesTarget.module_history.map(
                                        (event) => (
                                            <li key={event.id}>
                                                {moduleCatalog.find(
                                                    (module) =>
                                                        module.code ===
                                                        event.module,
                                                )?.name ?? event.module}{' '}
                                                {event.action === 'ATIVADO'
                                                    ? 'ativado'
                                                    : 'desativado'}{' '}
                                                por{' '}
                                                {event.administrator ??
                                                    'sistema'}{' '}
                                                em{' '}
                                                {new Date(
                                                    event.occurred_at,
                                                ).toLocaleString('pt-BR')}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setModulesTargetId(null)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            onClick={saveModules}
                            disabled={moduleErrors.length > 0}
                        >
                            Salvar módulos
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={statusTarget !== null}
                onOpenChange={(open) => !open && setStatusTarget(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {statusTarget?.status === 'ativo'
                                ? 'Suspender gabinete?'
                                : 'Reativar gabinete?'}
                        </DialogTitle>
                        <DialogDescription>
                            {statusTarget?.status === 'ativo'
                                ? `Os usuários de ${statusTarget.name} perderão imediatamente o acesso operacional. Todos os dados serão preservados.`
                                : `Os usuários ativos de ${statusTarget?.name} voltarão a acessar o sistema.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setStatusTarget(null)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant={
                                statusTarget?.status === 'ativo'
                                    ? 'destructive-solid'
                                    : 'default'
                            }
                            onClick={changeStatus}
                        >
                            {statusTarget?.status === 'ativo'
                                ? 'Confirmar suspensão'
                                : 'Confirmar reativação'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function OfficeDetailsDialog({
    office,
    onClose,
}: {
    office: AdminOffice | null;
    onClose: () => void;
}) {
    const latestSync = office?.political_syncs[0];
    const holder = office?.office_holder_candidate;
    const holderName =
        holder?.ballot_name || holder?.name || office?.councilor_name;
    const holderDetails = [
        holder?.party,
        holder?.number ?? office?.candidate_number,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <Dialog
            open={office !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="flex max-h-[85vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-2 pr-8">
                        <DialogTitle>{office?.name}</DialogTitle>
                        {office && (
                            <Badge
                                variant={
                                    office.status === 'ativo'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {office.status_label}
                            </Badge>
                        )}
                    </div>
                    <DialogDescription>
                        {office?.entidade?.name ?? 'Entidade não definida'} ·{' '}
                        {office?.city}/{office?.state}
                    </DialogDescription>
                </DialogHeader>

                {office && (
                    <div className="flex-1 space-y-4 overflow-y-auto">
                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle as="h3">
                                    Pessoas e acesso
                                </SurfaceTitle>
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
                                    {office.active_users_count.toLocaleString(
                                        'pt-BR',
                                    )}{' '}
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
                                    {office.citizens_count.toLocaleString(
                                        'pt-BR',
                                    )}
                                </OfficeDetail>
                                <OfficeDetail label="Demandas">
                                    {office.demands_count.toLocaleString(
                                        'pt-BR',
                                    )}
                                </OfficeDetail>
                                <OfficeDetail label="Demandas abertas">
                                    {office.open_demands_count.toLocaleString(
                                        'pt-BR',
                                    )}
                                </OfficeDetail>
                            </dl>
                        </Surface>

                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle as="h3">
                                    Dados políticos
                                </SurfaceTitle>
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
                                            ? (datasetLabels[
                                                  latestSync.dataset
                                              ] ?? latestSync.dataset)
                                            : undefined
                                    }
                                >
                                    {latestSync ? (
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <SyncStatusDot
                                                status={latestSync.status}
                                            />
                                            <span className="text-xs font-medium tracking-wide uppercase">
                                                {syncStatusLabels[
                                                    latestSync.status
                                                ] ?? latestSync.status}
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
                                    O que já foi importado do TSE para este
                                    município
                                </p>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {office.political_data_checklist
                                        .filter(
                                            (item) => item.key !== 'electorate',
                                        )
                                        .map((item) => (
                                            <li
                                                key={item.key}
                                                className="flex items-start gap-2 text-sm"
                                            >
                                                {item.available ? (
                                                    <CheckCircleIcon className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                                ) : (
                                                    <CloseCircleIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                                )}
                                                <span className="min-w-0">
                                                    <span
                                                        className={
                                                            item.available
                                                                ? undefined
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {item.label}
                                                    </span>
                                                    {item.note && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {item.note}
                                                        </span>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                </ul>
                            </div>
                        </Surface>
                    </div>
                )}

                <DialogFooter>
                    {office?.modules.includes('POLITICA') && (
                        <Button variant="outline" asChild>
                            <Link href="/admin/sincronizacao-politica">
                                <RefreshIcon aria-hidden="true" />
                                Sincronização política
                            </Link>
                        </Button>
                    )}
                    <Button type="button" onClick={onClose}>
                        Fechar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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

Offices.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Gabinetes', href: '/admin/gabinetes' },
    ],
};
