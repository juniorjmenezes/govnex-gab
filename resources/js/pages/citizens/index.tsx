import { Head, Link, router } from '@inertiajs/react';
import {
    AddIcon,
    CloseIcon,
    MagnifierIcon,
    PenIcon,
    UsersGroupRoundedIcon,
} from '@solar-icons/react/outline';
import { useEffect, useRef, useState } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { maskPhone } from '@/lib/masks';
import { cn } from '@/lib/utils';
import type { Citizen, Pagination } from '@/types';

export default function CitizensIndex({
    citizens,
    filters,
    canDelete,
}: {
    citizens: Pagination<Citizen>;
    filters: { q: string };
    canDelete: boolean;
}) {
    const [query, setQuery] = useState(filters.q);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get('/cidadaos', query ? { q: query } : {}, {
                preserveState: true,
                replace: true,
            });
        }, 400);

        return () => clearTimeout(timeout);
    }, [query]);

    return (
        <>
            <Head title="Cidadãos" />
            <PageContainer>
                <PageHeader
                    title="Cidadãos"
                    description="Base de contatos atendidos pelo gabinete, isolada por equipe."
                    actions={
                        <Button asChild>
                            <Link href="/cidadaos/create">
                                <AddIcon />
                                Novo cidadão
                            </Link>
                        </Button>
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
                            onChange={(e) => setQuery(e.target.value)}
                            className="pl-9"
                            placeholder="Buscar por nome, telefone, WhatsApp ou e-mail"
                        />
                    </div>
                    {query && (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={() => setQuery('')}
                            aria-label="Limpar busca"
                        >
                            <CloseIcon />
                        </Button>
                    )}
                </form>
                <Surface as="section" className="overflow-hidden">
                    {citizens.data.length === 0 ? (
                        <EmptyState
                            icon={UsersGroupRoundedIcon}
                            title="Nenhum cidadão encontrado"
                            description="Cadastre o primeiro contato ou ajuste os termos da busca."
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Nome</TableHead>
                                        <TableHead>Contato</TableHead>
                                        <TableHead>Bairro</TableHead>
                                        <TableHead>Consentimento</TableHead>
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {citizens.data.map((citizen) => (
                                        <TableRow key={citizen.id}>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        className="font-normal hover:underline"
                                                        href={`/cidadaos/${citizen.id}`}
                                                    >
                                                        {citizen.nome}
                                                    </Link>
                                                    <Badge
                                                        variant={
                                                            citizen.eleitor
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {citizen.eleitor
                                                            ? 'Eleitor'
                                                            : 'Não eleitor'}
                                                    </Badge>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {citizen.email ??
                                                        'Sem e-mail'}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                {citizen.whatsapp ||
                                                citizen.telefone
                                                    ? maskPhone(
                                                          citizen.whatsapp ??
                                                              citizen.telefone,
                                                      )
                                                    : 'Não informado'}
                                            </TableCell>
                                            <TableCell>
                                                {citizen.bairro?.nome ??
                                                    'Não informado'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        citizen.consentimento_contato
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {citizen.consentimento_contato
                                                        ? 'Autorizado'
                                                        : 'Não autorizado'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <TableActionButton
                                                        asChild
                                                        label={`Editar ${citizen.nome}`}
                                                    >
                                                        <Link
                                                            href={`/cidadaos/${citizen.id}/edit`}
                                                        >
                                                            <PenIcon aria-hidden="true" />
                                                        </Link>
                                                    </TableActionButton>
                                                    {canDelete && (
                                                        <DeleteRecordButton
                                                            url={`/cidadaos/${citizen.id}`}
                                                            label={`Excluir ${citizen.nome}`}
                                                            title="Excluir cidadão?"
                                                            description="O cadastro deixará de aparecer nas consultas. Demandas e atendimentos históricos serão preservados."
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                                Exibindo {citizens.from}–{citizens.to} de{' '}
                                {citizens.total} cidadão(s)
                            </div>
                            <PaginationLinks links={citizens.links} />
                        </>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

CitizensIndex.layout = {
    breadcrumbs: [{ title: 'Cidadãos', href: '/cidadaos' }],
};
