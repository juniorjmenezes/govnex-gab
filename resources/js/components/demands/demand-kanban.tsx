import {
    closestCorners,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    TouchSensor,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    DragEndEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { PriorityBadge } from '@/components/demands/priority-badge';
import {
    CalendarMarkIcon,
    MapPointIcon,
    PaperclipIcon,
    ReorderIcon,
    UserCircleIcon,
    UserRoundedIcon,
} from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type {
    DemandKanbanColumn,
    DemandKanbanItem,
    DemandKanbanTransitions,
    DemandStatus,
} from '@/types';

const formatDate = (date: string | null) =>
    date
        ? new Intl.DateTimeFormat('pt-BR', {
              day: '2-digit',
              month: 'short',
          }).format(new Date(date))
        : 'Sem prazo';

export function DemandKanban({
    initialColumns,
    transitions,
}: {
    initialColumns: DemandKanbanColumn[];
    transitions: DemandKanbanTransitions;
}) {
    const tenantUrl = useTenantUrl();
    const [columns, setColumns] = useState(initialColumns);
    const [activeId, setActiveId] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 6 },
        }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 180, tolerance: 8 },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const activeDemand = useMemo(
        () =>
            columns
                .flatMap((column) => column.demands)
                .find((demand) => demand.id === activeId) ?? null,
        [activeId, columns],
    );

    const findStatus = (id: UniqueIdentifier): DemandStatus | null => {
        const directColumn = columns.find((column) => column.status === id);

        if (directColumn) {
            return directColumn.status;
        }

        return (
            columns.find((column) =>
                column.demands.some((demand) => demand.id === Number(id)),
            )?.status ?? null
        );
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        if (!saving) {
            setActiveId(Number(active.id));
        }
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setActiveId(null);

        if (!over || saving) {
            return;
        }

        const demand = columns
            .flatMap((column) => column.demands)
            .find((item) => item.id === Number(active.id));
        const targetStatus = findStatus(over.id);

        if (!demand || !targetStatus || demand.status === targetStatus) {
            return;
        }

        if (!demand.allowed_transitions.includes(targetStatus)) {
            toast.error('Essa transição de status não é permitida.');

            return;
        }

        const previous = columns;
        const optimisticDemand = {
            ...demand,
            status: targetStatus,
            allowed_transitions: transitions[targetStatus],
        };
        setColumns((current) =>
            current.map((column) => {
                if (column.status === demand.status) {
                    return {
                        ...column,
                        total: Math.max(0, column.total - 1),
                        demands: column.demands.filter(
                            (item) => item.id !== demand.id,
                        ),
                    };
                }

                if (column.status === targetStatus) {
                    return {
                        ...column,
                        total: column.total + 1,
                        demands: [optimisticDemand, ...column.demands],
                    };
                }

                return column;
            }),
        );
        setSaving(true);
        router.patch(
            tenantUrl(`/demandas/${demand.id}/kanban-status`),
            { status: targetStatus },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    setColumns(previous);
                    toast.error(
                        Object.values(errors)[0] ??
                            'Não foi possível mover a demanda.',
                    );
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={onDragStart}
            onDragCancel={() => setActiveId(null)}
            onDragEnd={onDragEnd}
            accessibility={{
                screenReaderInstructions: {
                    draggable:
                        'Pressione espaço para iniciar. Use as setas para mover a demanda entre os status e espaço novamente para soltar.',
                },
                announcements: {
                    onDragStart: ({ active }) =>
                        `Demanda ${active.id} selecionada.`,
                    onDragOver: ({ over }) =>
                        over
                            ? `Demanda sobre ${String(over.id)}.`
                            : 'Demanda fora de uma coluna.',
                    onDragEnd: ({ over }) =>
                        over
                            ? `Demanda solta em ${String(over.id)}.`
                            : 'Movimentação cancelada.',
                    onDragCancel: () => 'Movimentação cancelada.',
                },
            }}
        >
            <div
                className="grid auto-cols-[minmax(18rem,21rem)] grid-flow-col gap-4 overflow-x-auto pb-4"
                aria-label="Quadro Kanban de demandas"
            >
                {columns.map((column) => (
                    <KanbanColumn
                        key={column.status}
                        column={column}
                        disabled={saving}
                    />
                ))}
            </div>
            <DragOverlay>
                {activeDemand ? (
                    <Surface className="w-80 rotate-1 p-4">
                        <CardContent demand={activeDemand} />
                    </Surface>
                ) : null}
            </DragOverlay>
        </DndContext>
    );
}

function KanbanColumn({
    column,
    disabled,
}: {
    column: DemandKanbanColumn;
    disabled: boolean;
}) {
    const { setNodeRef, isOver } = useDroppable({
        id: column.status,
        disabled,
    });

    return (
        <section
            ref={setNodeRef}
            className={cn(
                'flex max-h-[calc(100vh-16rem)] min-h-80 flex-col rounded-lg border bg-muted/25',
                isOver && 'border-primary bg-primary/5',
            )}
            aria-labelledby={`column-${column.status}`}
        >
            <header className="flex items-center justify-between border-b px-3 py-3">
                <h2
                    id={`column-${column.status}`}
                    className="text-xs font-semibold tracking-wide text-foreground uppercase"
                >
                    {column.label}
                </h2>
                <span className="rounded-full border bg-background px-2 py-0.5 text-xs text-muted-foreground tabular-nums">
                    {column.total}
                </span>
            </header>
            <SortableContext
                items={column.demands.map((demand) => demand.id)}
                strategy={verticalListSortingStrategy}
            >
                <div className="min-h-28 flex-1 space-y-3 overflow-y-auto p-3">
                    {column.demands.length === 0 ? (
                        <p className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
                            Solte uma demanda aqui
                        </p>
                    ) : (
                        column.demands.map((demand) => (
                            <KanbanCard
                                key={demand.id}
                                demand={demand}
                                disabled={
                                    disabled ||
                                    demand.allowed_transitions.length === 0
                                }
                            />
                        ))
                    )}
                    {column.truncated && (
                        <p className="px-2 text-center text-xs text-muted-foreground">
                            Exibindo as 60 demandas mais prioritárias desta
                            coluna.
                        </p>
                    )}
                </div>
            </SortableContext>
        </section>
    );
}

function KanbanCard({
    demand,
    disabled,
}: {
    demand: DemandKanbanItem;
    disabled: boolean;
}) {
    const tenantUrl = useTenantUrl();
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: demand.id,
        data: { status: demand.status },
        disabled,
    });

    return (
        <article
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
            className={cn(surfaceClasses, 'p-3', isDragging && 'opacity-30')}
        >
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <Link
                        href={tenantUrl(`/demandas/${demand.id}`)}
                        className="font-mono text-[11px] font-semibold text-muted-foreground hover:text-foreground"
                    >
                        {demand.protocolo}
                    </Link>
                    <Link
                        href={tenantUrl(`/demandas/${demand.id}`)}
                        className="mt-1 line-clamp-2 block text-sm leading-5 font-semibold hover:underline"
                    >
                        {demand.titulo}
                    </Link>
                </div>
                <Button
                    ref={setActivatorNodeRef}
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0 cursor-grab touch-none active:cursor-grabbing"
                    aria-label={`Mover demanda ${demand.protocolo}`}
                    disabled={disabled}
                    {...attributes}
                    {...listeners}
                >
                    <ReorderIcon />
                </Button>
            </div>
            <CardContent demand={demand} />
        </article>
    );
}

function CardContent({ demand }: { demand: DemandKanbanItem }) {
    return (
        <>
            <div className="mt-3">
                <PriorityBadge priority={demand.prioridade} />
            </div>
            <dl className="mt-3 space-y-1.5 text-xs text-muted-foreground">
                <CardDetail icon={UserCircleIcon} text={demand.cidadao.nome} />
                <CardDetail
                    icon={MapPointIcon}
                    text={demand.bairro?.nome ?? 'Bairro não informado'}
                />
                <CardDetail
                    icon={UserRoundedIcon}
                    text={demand.responsavel?.name ?? 'Não atribuído'}
                />
            </dl>
            <div className="mt-3 flex items-center justify-between border-t pt-3 text-xs">
                <span
                    className={cn(
                        'inline-flex items-center gap-1 text-muted-foreground',
                        demand.atrasada && 'font-semibold text-destructive',
                    )}
                >
                    <CalendarMarkIcon className="size-3.5" />
                    {formatDate(demand.prazo)}
                </span>
                <span
                    className="inline-flex items-center gap-1 text-muted-foreground"
                    title={`${demand.anexos_count} anexos`}
                >
                    <PaperclipIcon className="size-3.5" />
                    {demand.anexos_count}
                </span>
            </div>
        </>
    );
}

function CardDetail({
    icon: Icon,
    text,
}: {
    icon: typeof UserCircleIcon;
    text: string;
}) {
    return (
        <div className="flex min-w-0 items-center gap-1.5">
            <Icon className="size-3.5 shrink-0" />
            <dd className="truncate">{text}</dd>
        </div>
    );
}
