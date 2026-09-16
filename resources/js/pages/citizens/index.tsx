import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { VoterMark } from '@/components/citizens/voter-mark';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AddIcon,
    CloseIcon,
    DislikeIcon,
    LikeIcon,
    MagnifierIcon,
    PenIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
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
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { maskPhone } from '@/lib/masks';
import { preservedListParams } from '@/lib/pagination';
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
    const tenantUrl = useTenantUrl();
    const [query, setQuery] = useState(filters.q);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                tenantUrl('/cidadaos'),
                { ...(query && { q: query }), ...preservedListParams() },
                {
                    preserveState: true,
                    replace: true,
                },
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [query, tenantUrl]);

    return (
        <>
            <Head title="Cidadãos" />
            <PageContainer>
                <PageHeader
                    title="Cidadãos"
                    description="Base de contatos atendidos pelo gabinete, isolada por equipe."
                    actions={
                        <Button asChild>
                            <Link href={tenantUrl('/cidadaos/create')}>
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
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Eleitor
                                            </span>
                                        </TableHead>
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
                                            <TableCell className="w-10">
                                                <VoterMark
                                                    voter={citizen.eleitor}
                                                    showWhenNotVoter
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        className="font-normal hover:underline"
                                                        href={tenantUrl(
                                                            `/cidadaos/${citizen.id}`,
                                                        )}
                                                    >
                                                        {citizen.nome}
                                                    </Link>
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
                                                <ConsentMark
                                                    authorized={
                                                        citizen.consentimento_contato
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <TableActionButton
                                                        asChild
                                                        label={`Editar ${citizen.nome}`}
                                                    >
                                                        <Link
                                                            href={tenantUrl(
                                                                `/cidadaos/${citizen.id}/edit`,
                                                            )}
                                                        >
                                                            <PenIcon aria-hidden="true" />
                                                        </Link>
                                                    </TableActionButton>
                                                    {canDelete && (
                                                        <DeleteRecordButton
                                                            url={tenantUrl(
                                                                `/cidadaos/${citizen.id}`,
                                                            )}
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
                            <PaginationLinks
                                pagination={citizens}
                                label="cidadão(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

/**
 * Consentimento de contato como ícone: polegar para cima na cor do gabinete
 * quando autorizado, para baixo em cinza quando não. O texto fica no title
 * (hover) e em sr-only, para leitores de tela.
 */
function ConsentMark({ authorized }: { authorized: boolean }) {
    const label = authorized ? 'Contato autorizado' : 'Contato não autorizado';
    const Icon = authorized ? LikeIcon : DislikeIcon;

    return (
        <span className="inline-flex" title={label}>
            <Icon
                className={
                    authorized
                        ? 'size-4 text-primary'
                        : 'size-4 text-muted-foreground'
                }
                aria-hidden="true"
            />
            <span className="sr-only">{label}</span>
        </span>
    );
}

CitizensIndex.layout = {
    breadcrumbs: [{ title: 'Cidadãos', href: '/cidadaos' }],
};
