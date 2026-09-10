import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DemandKanban } from '@/components/demands/demand-kanban';
import {
    AddIcon,
    CloseIcon,
    ListIcon,
    MagnifierIcon,
    ThreeSquaresIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { surfaceClasses } from '@/components/ui/surface';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type {
    DemandKanbanColumn,
    DemandKanbanFilters,
    DemandKanbanOptions,
    DemandKanbanTransitions,
} from '@/types';

export default function DemandsKanban({
    columns,
    transitions,
    filters,
    options,
}: {
    columns: DemandKanbanColumn[];
    transitions: DemandKanbanTransitions;
    filters: DemandKanbanFilters;
    options: DemandKanbanOptions;
}) {
    const tenantUrl = useTenantUrl();
    const [query, setQuery] = useState(filters.q);
    const [priority, setPriority] = useState(filters.prioridade);
    const [responsible, setResponsible] = useState(
        filters.responsavel_id?.toString() ?? '',
    );

    const visit = () => {
        router.get(
            tenantUrl('/demandas/kanban'),
            {
                q: query,
                prioridade: priority,
                responsavel_id: responsible,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(visit, 400);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, priority, responsible]);
    const hasFilters = Boolean(query || priority || responsible);
    const clear = () => {
        setQuery('');
        setPriority('');
        setResponsible('');
        router.get(
            tenantUrl('/demandas/kanban'),
            {},
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Kanban de demandas" />
            <PageContainer className="max-w-none">
                <PageHeader
                    title="Kanban de demandas"
                    description="Movimente os cards entre os status para atualizar o atendimento."
                    actions={
                        <>
                            <div className="flex rounded-md border p-0.5">
                                <Button asChild size="sm" variant="ghost">
                                    <Link href={tenantUrl('/demandas')}>
                                        <ListIcon />
                                        Lista
                                    </Link>
                                </Button>
                                <Button size="sm" variant="secondary" disabled>
                                    <ThreeSquaresIcon />
                                    Kanban
                                </Button>
                            </div>
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
                    onSubmit={(event) => event.preventDefault()}
                    className={cn(
                        surfaceClasses,
                        'flex flex-wrap items-center gap-3 p-4',
                    )}
                >
                    <div className="relative min-w-56 flex-1">
                        <MagnifierIcon className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            className="pl-9"
                            placeholder="Buscar protocolo, título ou cidadão"
                        />
                    </div>
                    <AppSelect
                        value={priority}
                        onValueChange={(value) =>
                            setPriority(
                                value as DemandKanbanFilters['prioridade'],
                            )
                        }
                        options={options.priorities}
                        emptyLabel="Todas as prioridades"
                        aria-label="Filtrar por prioridade"
                        className="w-52"
                    />
                    <AppSelect
                        value={responsible}
                        onValueChange={setResponsible}
                        options={options.members.map((member) => ({
                            value: member.id.toString(),
                            label: member.name,
                        }))}
                        emptyLabel="Todos os responsáveis"
                        aria-label="Filtrar por responsável"
                        className="w-52"
                    />
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={clear}
                            aria-label="Limpar filtros"
                        >
                            <CloseIcon />
                        </Button>
                    )}
                </form>

                <DemandKanban
                    initialColumns={columns}
                    transitions={transitions}
                />
            </PageContainer>
        </>
    );
}

DemandsKanban.layout = {
    breadcrumbs: [
        { title: 'Demandas', href: '/demandas' },
        { title: 'Kanban', href: '/demandas/kanban' },
    ],
};
