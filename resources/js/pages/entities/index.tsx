import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { PaginationLinks } from '@/components/common/pagination-links';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AddIcon,
    AltArrowRightIcon,
    Buildings2Icon,
    BuildingsIcon,
    CloseIcon,
    MagnifierIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { preservedListParams } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { Pagination } from '@/types';

type Gabinete = {
    id: number;
    name: string;
    slug: string;
    type: string;
    type_label: string;
};

type Entidade = {
    id: number;
    name: string;
    slug: string;
    type: string;
    type_label: string;
    city: string;
    state: string;
    can_create_gabinete: boolean;
    gabinetes: Gabinete[];
};

export default function Entidades({
    entidades,
    filters,
    isRoot,
}: {
    entidades: Pagination<Entidade>;
    filters: { q: string };
    isRoot: boolean;
}) {
    const [query, setQuery] = useState(filters.q);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                '/entidades',
                { ...(query && { q: query }), ...preservedListParams() },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [query]);

    return (
        <>
            <Head title="Entidades" />
            <PageContainer>
                <PageHeader
                    title="Entidades"
                    description="Escolha uma entidade para visualizar seus gabinetes e então entrar no ambiente de trabalho desejado."
                    actions={
                        isRoot ? (
                            <Button asChild>
                                <Link href="/admin/entidades/nova">
                                    <AddIcon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Nova entidade
                                </Link>
                            </Button>
                        ) : undefined
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
                            className="pl-9"
                            placeholder="Buscar por entidade, gabinete, município ou UF"
                            aria-label="Buscar entidades"
                        />
                    </div>
                    {query !== '' && (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={() => setQuery('')}
                            aria-label="Limpar busca"
                        >
                            <CloseIcon aria-hidden="true" />
                        </Button>
                    )}
                </form>

                {entidades.data.length === 0 ? (
                    <Surface as="section" className="overflow-hidden">
                        <EmptyState
                            icon={Buildings2Icon}
                            title="Nenhuma entidade encontrada"
                            description="Ajuste os termos da busca ou verifique seus vínculos de acesso."
                        />
                    </Surface>
                ) : (
                    <Surface as="section" className="overflow-hidden">
                        <div className="grid gap-4 p-4 lg:grid-cols-2">
                            {entidades.data.map((entidade) => (
                                <Surface
                                    as="article"
                                    key={entidade.id}
                                    className="flex h-full flex-col overflow-hidden"
                                >
                                    <div className="flex items-center justify-between gap-3 border-b p-4">
                                        <div className="min-w-0">
                                            <h2 className="truncate text-sm font-semibold">
                                                {entidade.name}
                                            </h2>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {entidade.city}/{entidade.state}
                                            </p>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className="shrink-0"
                                        >
                                            {entidade.type_label}
                                        </Badge>
                                    </div>

                                    <div className="flex flex-1 flex-col p-4">
                                        <div className="flex min-w-0 items-start gap-3 rounded-md bg-muted/40 p-3">
                                            <BuildingsIcon
                                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">
                                                    {entidade.gabinetes.length}{' '}
                                                    {entidade.gabinetes
                                                        .length === 1
                                                        ? 'gabinete disponível'
                                                        : 'gabinetes disponíveis'}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                    {entidade.gabinetes.length >
                                                    0
                                                        ? entidade.gabinetes
                                                              .slice(0, 3)
                                                              .map(
                                                                  (gabinete) =>
                                                                      gabinete.name,
                                                              )
                                                              .join(' · ')
                                                        : 'Nenhum gabinete acessível'}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={`/entidades/${entidade.slug}`}
                                                >
                                                    Abrir entidade
                                                    <AltArrowRightIcon
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                </Link>
                                            </Button>
                                            {entidade.can_create_gabinete && (
                                                <Button asChild>
                                                    <Link
                                                        href={`/admin/gabinetes/novo?entidade=${entidade.id}`}
                                                    >
                                                        <AddIcon
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        Novo gabinete
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </Surface>
                            ))}
                        </div>
                        <PaginationLinks
                            pagination={entidades}
                            label="entidade(s)"
                        />
                    </Surface>
                )}
            </PageContainer>
        </>
    );
}

Entidades.layout = {
    breadcrumbs: [{ title: 'Entidades', href: '/entidades' }],
};
