import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { PaginationLinks } from '@/components/common/pagination-links';
import { PriorityBadge } from '@/components/demands/priority-badge';
import { StatusBadge } from '@/components/demands/status-badge';
import { EmptyState } from '@/components/feedback/empty-state';
import { DateFilter, FilterSelect } from '@/components/forms/list-filters';
import {
    AddIcon,
    ChecklistIcon,
    CloseIcon,
    FilterIcon,
    HeartBoldIcon,
    HeartIcon as HeartOutlineIcon,
    MagnifierIcon,
    ThreeSquaresIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type {
    Demand,
    DemandFilters,
    DemandOptions,
    DemandTab,
    DemandsPage,
} from '@/types';

type FilterState = {
    q: string;
    prioridade: string;
    categoria_id: string;
    bairro_id: string;
    responsavel_id: string;
    origem: string;
    aberta_de: string;
    aberta_ate: string;
    sem_responsavel: boolean;
    per_page: string;
};

const tabs: Array<{ value: DemandTab; label: string }> = [
    { value: 'inbox', label: 'Caixa de entrada' },
    { value: 'mine', label: 'Minhas' },
    { value: 'awaiting', label: 'Aguardando' },
    { value: 'today', label: 'Para hoje' },
    { value: 'overdue', label: 'Atrasadas' },
];

const formatDate = (date: string | null) =>
    date
        ? new Intl.DateTimeFormat('pt-BR', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
          }).format(new Date(date))
        : 'Sem prazo';

export default function DemandsIndex({
    demands,
    filters,
    options,
    tabCounts,
}: {
    demands: DemandsPage;
    filters: DemandFilters;
    options: DemandOptions;
    tabCounts: Record<DemandTab, number>;
}) {
    const tenantUrl = useTenantUrl();
    const [filterState, setFilterState] = useState<FilterState>({
        q: filters.q,
        prioridade: filters.prioridade,
        categoria_id: filters.categoria_id?.toString() ?? '',
        bairro_id: filters.bairro_id?.toString() ?? '',
        responsavel_id: filters.responsavel_id?.toString() ?? '',
        origem: filters.origem,
        aberta_de: filters.aberta_de,
        aberta_ate: filters.aberta_ate,
        sem_responsavel: filters.sem_responsavel,
        per_page: filters.per_page.toString(),
    });
    const [advancedOpen, setAdvancedOpen] = useState(false);

    const visit = (state: FilterState, tab: DemandTab = filters.tab) => {
        router.get(
            tenantUrl('/demandas'),
            { ...state, tab, sem_responsavel: state.sem_responsavel ? 1 : 0 },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit(filterState);
    };
    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => visit(filterState), 400);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filterState.q, filterState.prioridade]);
    const hasFilters = Boolean(
        filterState.q ||
        filterState.prioridade ||
        filterState.categoria_id ||
        filterState.bairro_id ||
        filterState.responsavel_id ||
        filterState.origem ||
        filterState.aberta_de ||
        filterState.aberta_ate ||
        filterState.sem_responsavel,
    );
    const clearFilters = () => {
        const cleared: FilterState = {
            q: '',
            prioridade: '',
            categoria_id: '',
            bairro_id: '',
            responsavel_id: '',
            origem: '',
            aberta_de: '',
            aberta_ate: '',
            sem_responsavel: false,
            per_page: '15',
        };
        setFilterState(cleared);
        visit(cleared);
    };
    const setFilter = <Key extends keyof FilterState>(
        key: Key,
        value: FilterState[Key],
    ) => setFilterState((current) => ({ ...current, [key]: value }));

    return (
        <>
            <Head title="Demandas" />
            <PageContainer>
                <PageHeader
                    title="Demandas"
                    description="Registre primeiro, organize durante o atendimento."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={tenantUrl('/demandas/kanban')}>
                                    <ThreeSquaresIcon />
                                    Kanban
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={tenantUrl('/demandas/create')}>
                                    <AddIcon />
                                    Nova demanda
                                </Link>
                            </Button>
                        </>
                    }
                />

                <form
                    onSubmit={submit}
                    className={cn(
                        surfaceClasses,
                        'flex flex-wrap items-center gap-3 p-4',
                    )}
                >
                    <div className="relative min-w-56 flex-1">
                        <MagnifierIcon className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                        <Input
                            value={filterState.q}
                            onChange={(event) =>
                                setFilter('q', event.target.value)
                            }
                            className="pl-9"
                            placeholder="Buscar por protocolo, assunto, descrição ou solicitante"
                        />
                    </div>
                    <AppSelect
                        value={filterState.prioridade}
                        onValueChange={(value) =>
                            setFilter('prioridade', value)
                        }
                        options={options.priorities}
                        emptyLabel="Todas as prioridades"
                        aria-label="Filtrar por prioridade"
                        className="w-52"
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
                    <div className="ml-auto flex items-center gap-2 border-l pl-3">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={() => setAdvancedOpen(true)}
                            aria-label="Filtros avançados"
                        >
                            <FilterIcon />
                        </Button>
                    </div>
                </form>

                <nav className="flex w-fit flex-wrap rounded-md border p-0.5">
                    {tabs.map((tab) => (
                        <Button
                            key={tab.value}
                            type="button"
                            size="sm"
                            variant="ghost"
                            className={cn(
                                filters.tab === tab.value &&
                                    'bg-primary/10 text-primary hover:bg-primary/10 hover:text-primary',
                            )}
                            onClick={() => visit(filterState, tab.value)}
                        >
                            {tab.label}
                            <span className="opacity-40" aria-hidden="true">
                                ·
                            </span>
                            <span className="tabular-nums opacity-60">
                                {tabCounts[tab.value]}
                            </span>
                        </Button>
                    ))}
                </nav>

                <Drawer
                    open={advancedOpen}
                    onOpenChange={setAdvancedOpen}
                    swipeDirection="right"
                >
                    <DrawerContent>
                        <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                            <DrawerTitle>Filtros avançados</DrawerTitle>
                            <DrawerClose
                                render={
                                    <Button variant="ghost" size="icon-sm" />
                                }
                                aria-label="Fechar"
                            >
                                <CloseIcon aria-hidden="true" />
                            </DrawerClose>
                        </DrawerHeader>
                        <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-4">
                            <div className="grid gap-4">
                                <FilterSelect
                                    label="Categoria"
                                    value={filterState.categoria_id}
                                    onChange={(value) =>
                                        setFilter('categoria_id', value)
                                    }
                                    options={options.categories.map((item) => ({
                                        value: item.id.toString(),
                                        label: item.nome ?? '',
                                    }))}
                                />
                                <FilterSelect
                                    label="Bairro"
                                    value={filterState.bairro_id}
                                    onChange={(value) =>
                                        setFilter('bairro_id', value)
                                    }
                                    options={options.neighborhoods.map(
                                        (item) => ({
                                            value: item.id.toString(),
                                            label: item.nome ?? '',
                                        }),
                                    )}
                                />
                                <FilterSelect
                                    label="Responsável"
                                    value={filterState.responsavel_id}
                                    onChange={(value) =>
                                        setFilter('responsavel_id', value)
                                    }
                                    options={options.members.map((item) => ({
                                        value: item.id.toString(),
                                        label: item.name,
                                    }))}
                                />
                                <FilterSelect
                                    label="Origem"
                                    value={filterState.origem}
                                    onChange={(value) =>
                                        setFilter('origem', value)
                                    }
                                    options={options.origins}
                                />
                                <DateFilter
                                    label="Abertas de"
                                    value={filterState.aberta_de}
                                    onChange={(value) =>
                                        setFilter('aberta_de', value)
                                    }
                                />
                                <DateFilter
                                    label="Abertas até"
                                    value={filterState.aberta_ate}
                                    onChange={(value) =>
                                        setFilter('aberta_ate', value)
                                    }
                                />
                            </div>
                            <div className="flex items-center justify-between gap-3 rounded-md border bg-background p-3">
                                <div className="min-w-0">
                                    <Label htmlFor="filter-unassigned">
                                        Sem responsável
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Ainda não atribuídas
                                    </p>
                                </div>
                                <Switch
                                    id="filter-unassigned"
                                    checked={filterState.sem_responsavel}
                                    onCheckedChange={(value) =>
                                        setFilter('sem_responsavel', value)
                                    }
                                />
                            </div>
                        </div>
                        <DrawerFooter className="flex-row justify-end border-t p-4">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={clearFilters}
                            >
                                Limpar filtros
                            </Button>
                            <Button
                                type="button"
                                onClick={() => {
                                    visit(filterState);
                                    setAdvancedOpen(false);
                                }}
                            >
                                <FilterIcon />
                                Aplicar filtros
                            </Button>
                        </DrawerFooter>
                    </DrawerContent>
                </Drawer>

                <Surface as="section" className="overflow-hidden">
                    {demands.data.length === 0 ? (
                        <EmptyState
                            icon={ChecklistIcon}
                            title="Nenhuma demanda por aqui"
                            description="Registre a primeira solicitação ou ajuste os filtros aplicados."
                        />
                    ) : (
                        <>
                            <div className="divide-y">
                                {demands.data.map((demand) => (
                                    <DemandRow
                                        key={demand.id}
                                        demand={demand}
                                    />
                                ))}
                            </div>
                            <PaginationLinks
                                pagination={demands}
                                label="demanda(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

function DemandRow({ demand }: { demand: Demand }) {
    const tenantUrl = useTenantUrl();
    const favorited = demand.favoritada_em !== null;
    const responsible = demand.responsavel?.name ?? 'Não atribuído';
    const urgent = demand.proxima_acao_descricao
        ? {
              text: `${demand.proxima_acao_descricao}${
                  demand.proxima_acao_data
                      ? ` · ${formatDate(demand.proxima_acao_data)}`
                      : ''
              }`,
              danger: demand.proxima_acao_atrasada,
          }
        : demand.prazo
          ? {
                text: `${demand.atrasada ? 'Venceu em ' : 'Prazo: '}${formatDate(demand.prazo)}`,
                danger: demand.atrasada,
            }
          : null;
    const meta = urgent ? `${responsible} · ${urgent.text}` : responsible;

    const toggleFavorite = () => {
        router.patch(
            tenantUrl(`/demandas/${demand.id}/favorito`),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <div className="group flex items-center transition-colors hover:bg-muted/40">
            <button
                type="button"
                onClick={toggleFavorite}
                aria-pressed={favorited}
                aria-label={
                    favorited
                        ? `Remover destaque de ${demand.protocolo}`
                        : `Destacar ${demand.protocolo}`
                }
                title={
                    favorited && demand.favoritada_por?.name
                        ? `Destacada por ${demand.favoritada_por.name}`
                        : undefined
                }
                className={cn(
                    'shrink-0 py-3 pr-3 pl-5 text-muted-foreground/50 transition-colors hover:text-primary focus-visible:text-primary focus-visible:outline-none',
                    favorited && 'text-primary',
                )}
            >
                {favorited ? (
                    <HeartBoldIcon className="size-4" />
                ) : (
                    <HeartOutlineIcon className="size-4" />
                )}
            </button>
            <Link
                href={tenantUrl(`/demandas/${demand.id}`)}
                className="flex min-w-0 flex-1 items-center gap-3 py-3 pr-5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            >
                <span className="shrink-0 text-sm font-normal tabular-nums">
                    {demand.protocolo}
                </span>
                <div className="shrink-0">
                    <PriorityBadge priority={demand.prioridade} />
                </div>
                <span
                    className="min-w-0 flex-1 truncate text-sm font-normal"
                    title={demand.titulo}
                >
                    {demand.titulo}
                </span>
                <span
                    className={cn(
                        'hidden shrink-0 text-xs whitespace-nowrap sm:inline',
                        urgent?.danger
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {meta}
                </span>
                <div className="shrink-0">
                    <StatusBadge status={demand.status} />
                </div>
            </Link>
        </div>
    );
}

DemandsIndex.layout = {
    breadcrumbs: [{ title: 'Demandas', href: '/demandas' }],
};
