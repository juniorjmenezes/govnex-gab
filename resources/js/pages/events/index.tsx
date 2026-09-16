import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { DateFilter, FilterSelect } from '@/components/forms/list-filters';
import {
    AddIcon,
    CalendarDateIcon,
    CloseIcon,
    EyeIcon,
    FilterIcon,
    MagnifierIcon,
    MapPointIcon,
    PenIcon,
    SettingsIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
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
import { Surface, surfaceClasses } from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { formatShortDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import type { EventIndexProps, EventStatus, EventType } from '@/types';

const statusVariants: Record<
    EventStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    planejado: 'secondary',
    confirmado: 'default',
    em_andamento: 'default',
    concluido: 'outline',
    cancelado: 'destructive',
};

const formatDate = (value: string) =>
    new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(
        new Date(value),
    );
const formatTime = (value: string) =>
    new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));

type FilterState = {
    q: string;
    tipo: string;
    status: string;
    duracao: string;
    responsavel_id: string;
    de: string;
    ate: string;
    per_page: string;
};

export default function EventsIndex({
    events,
    filters,
    options,
    canDelete,
}: EventIndexProps) {
    const tenantUrl = useTenantUrl();
    const [filterState, setFilterState] = useState<FilterState>({
        q: filters.q,
        tipo: filters.tipo,
        status: filters.status,
        duracao: filters.duracao,
        responsavel_id: filters.responsavel_id?.toString() ?? '',
        de: filters.de,
        ate: filters.ate,
        per_page: filters.per_page.toString(),
    });

    const visit = (state: FilterState) => {
        router.get(
            tenantUrl('/eventos'),
            { ...state },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit(filterState);
    };
    const setFilter = <Key extends keyof FilterState>(
        key: Key,
        value: FilterState[Key],
    ) => setFilterState((current) => ({ ...current, [key]: value }));
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [viewOpen, setViewOpen] = useState(false);

    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => visit(filterState), 400);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filterState.q, filterState.tipo, filterState.status]);

    const hasFilters = Boolean(
        filterState.q ||
        filterState.tipo ||
        filterState.status ||
        filterState.duracao ||
        filterState.responsavel_id ||
        filterState.de ||
        filterState.ate,
    );
    const clearFilters = () => {
        const cleared: FilterState = {
            q: '',
            tipo: '',
            status: '',
            duracao: '',
            responsavel_id: '',
            de: '',
            ate: '',
            per_page: '15',
        };
        setFilterState(cleared);
        visit(cleared);
    };

    const typeLabel = (value: EventType) =>
        options.types.find((option) => option.value === value)?.label ?? value;
    const statusLabel = (value: EventStatus) =>
        options.statuses.find((option) => option.value === value)?.label ??
        value;

    return (
        <>
            <Head title="Central de eventos" />
            <PageContainer>
                <PageHeader
                    title="Central de eventos"
                    description="Reuniões, eventos públicos, atos políticos e assembleias do gabinete."
                    actions={
                        <Button asChild>
                            <Link href={tenantUrl('/eventos/create')}>
                                <AddIcon />
                                Novo evento
                            </Link>
                        </Button>
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
                            placeholder="Título, descrição ou local"
                            aria-label="Buscar eventos"
                        />
                    </div>
                    <AppSelect
                        value={filterState.tipo}
                        onValueChange={(value) => setFilter('tipo', value)}
                        emptyLabel="Todos os tipos"
                        options={options.types}
                        aria-label="Filtrar por tipo"
                        className="w-52"
                    />
                    <AppSelect
                        value={filterState.status}
                        onValueChange={(value) => setFilter('status', value)}
                        emptyLabel="Todos os status"
                        options={options.statuses}
                        aria-label="Filtrar por status"
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
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={() => setViewOpen(true)}
                            aria-label="Visualização"
                        >
                            <SettingsIcon />
                        </Button>
                    </div>
                </form>

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
                        <div className="grid min-h-0 flex-1 auto-rows-min content-start gap-4 overflow-y-auto p-4">
                            <FilterSelect
                                label="Responsável"
                                value={filterState.responsavel_id}
                                onChange={(value) =>
                                    setFilter('responsavel_id', value)
                                }
                                options={options.members.map((member) => ({
                                    value: member.id.toString(),
                                    label: member.name,
                                }))}
                            />
                            <FilterSelect
                                label="Duração"
                                value={filterState.duracao}
                                onChange={(value) =>
                                    setFilter('duracao', value)
                                }
                                options={options.durations}
                            />
                            <DateFilter
                                label="De"
                                value={filterState.de}
                                onChange={(value) => setFilter('de', value)}
                            />
                            <DateFilter
                                label="Até"
                                value={filterState.ate}
                                onChange={(value) => setFilter('ate', value)}
                            />
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

                <Drawer
                    open={viewOpen}
                    onOpenChange={setViewOpen}
                    swipeDirection="right"
                >
                    <DrawerContent>
                        <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                            <DrawerTitle>Visualização</DrawerTitle>
                            <DrawerClose
                                render={
                                    <Button variant="ghost" size="icon-sm" />
                                }
                                aria-label="Fechar"
                            >
                                <CloseIcon aria-hidden="true" />
                            </DrawerClose>
                        </DrawerHeader>
                        <div className="min-h-0 flex-1 overflow-y-auto p-4"></div>
                        <DrawerFooter className="flex-row justify-end border-t p-4">
                            <Button
                                type="button"
                                onClick={() => {
                                    visit(filterState);
                                    setViewOpen(false);
                                }}
                            >
                                Aplicar
                            </Button>
                        </DrawerFooter>
                    </DrawerContent>
                </Drawer>

                <Surface as="section" className="overflow-hidden">
                    {events.data.length === 0 ? (
                        <EmptyState
                            icon={CalendarDateIcon}
                            title="Nenhum evento encontrado"
                            description="Crie o primeiro evento ou ajuste os filtros utilizados."
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Evento</TableHead>
                                        <TableHead>Período</TableHead>
                                        <TableHead>Tipo</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Responsável</TableHead>
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {events.data.map((event) => (
                                        <TableRow key={event.id}>
                                            <TableCell>
                                                <Link
                                                    href={tenantUrl(
                                                        `/eventos/${event.id}`,
                                                    )}
                                                    className="font-normal hover:underline"
                                                >
                                                    {event.titulo}
                                                </Link>
                                                {event.local && (
                                                    <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                        <MapPointIcon className="size-3" />
                                                        {event.local}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {event.duracao ===
                                                'multiplos_dias' ? (
                                                    <>
                                                        <span className="block">
                                                            {formatDate(
                                                                event.inicio_em,
                                                            )}{' '}
                                                            até{' '}
                                                            {formatDate(
                                                                event.fim_em,
                                                            )}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            diariamente,{' '}
                                                            {formatTime(
                                                                event.inicio_em,
                                                            )}{' '}
                                                            às{' '}
                                                            {formatTime(
                                                                event.fim_em,
                                                            )}
                                                        </span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <span className="block">
                                                            {formatShortDateTime(
                                                                event.inicio_em,
                                                            )}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            até{' '}
                                                            {formatTime(
                                                                event.fim_em,
                                                            )}
                                                        </span>
                                                    </>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {typeLabel(event.tipo)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        statusVariants[
                                                            event.status
                                                        ]
                                                    }
                                                >
                                                    {statusLabel(event.status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {event.responsavel?.name ??
                                                    'Não informado'}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <TableActionButton
                                                        asChild
                                                        label={`Visualizar ${event.titulo}`}
                                                    >
                                                        <Link
                                                            href={tenantUrl(
                                                                `/eventos/${event.id}`,
                                                            )}
                                                        >
                                                            <EyeIcon aria-hidden="true" />
                                                        </Link>
                                                    </TableActionButton>
                                                    <TableActionButton
                                                        asChild
                                                        label={`Editar ${event.titulo}`}
                                                    >
                                                        <Link
                                                            href={tenantUrl(
                                                                `/eventos/${event.id}/edit`,
                                                            )}
                                                        >
                                                            <PenIcon aria-hidden="true" />
                                                        </Link>
                                                    </TableActionButton>
                                                    {canDelete && (
                                                        <DeleteRecordButton
                                                            url={tenantUrl(
                                                                `/eventos/${event.id}`,
                                                            )}
                                                            label={`Excluir ${event.titulo}`}
                                                            title="Excluir evento?"
                                                            description="O evento deixará de aparecer na central."
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <PaginationLinks
                                pagination={events}
                                label="evento(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Eventos', href: '/eventos' }],
};
