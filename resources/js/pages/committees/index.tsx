import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { FieldError } from '@/components/forms/field-error';
import { EyeIcon, PenIcon, UsersGroupTwoRoundedIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { CommitteeIndexProps, CommitteeType } from '@/types';

type FormValues = { nome: string; sigla: string; tipo: CommitteeType };

/**
 * Comissões legislativas da Câmara. Todo gabinete da entidade lê; só a
 * Secretaria legislativa cadastra (formulário em linha no topo) e gerencia
 * membros e documentos na página de cada comissão.
 */
export default function CommitteesIndex({
    committees,
    canManage,
    options,
}: CommitteeIndexProps) {
    const tenantUrl = useTenantUrl();
    const form = useForm<FormValues>({
        nome: '',
        sigla: '',
        tipo: 'permanente',
    });
    const activeCount = committees.filter(
        (item) => item.status === 'ativa',
    ).length;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(tenantUrl('/comissoes'), {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <>
            <Head title="Comissões" />
            <PageContainer>
                <PageHeader
                    title="Comissões"
                    description="Comissões legislativas da Câmara, com a composição e os documentos de cada uma."
                />

                {canManage && (
                    <Surface as="section" className="overflow-hidden">
                        <SurfaceHeader>
                            <SurfaceTitle>Nova comissão</SurfaceTitle>
                        </SurfaceHeader>
                        <form
                            noValidate
                            onSubmit={submit}
                            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start"
                        >
                            <div className="w-full flex-1 space-y-1">
                                <Input
                                    aria-label="Nome da comissão"
                                    placeholder="Ex.: Comissão de Finanças e Orçamento"
                                    value={form.data.nome}
                                    onChange={(event) =>
                                        form.setData('nome', event.target.value)
                                    }
                                    maxLength={180}
                                    aria-invalid={Boolean(form.errors.nome)}
                                />
                                <FieldError message={form.errors.nome} />
                            </div>
                            <div className="w-full space-y-1 sm:w-32">
                                <Input
                                    aria-label="Sigla"
                                    placeholder="Sigla"
                                    value={form.data.sigla}
                                    onChange={(event) =>
                                        form.setData(
                                            'sigla',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={20}
                                    aria-invalid={Boolean(form.errors.sigla)}
                                />
                                <FieldError message={form.errors.sigla} />
                            </div>
                            <div className="w-full space-y-1 sm:w-48">
                                <AppSelect
                                    aria-label="Tipo da comissão"
                                    value={form.data.tipo}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'tipo',
                                            (value ||
                                                'permanente') as CommitteeType,
                                        )
                                    }
                                    options={options.types}
                                    clearable={false}
                                />
                                <FieldError message={form.errors.tipo} />
                            </div>
                            <Button
                                className="w-full shrink-0 sm:w-auto"
                                disabled={form.processing}
                            >
                                Cadastrar comissão
                            </Button>
                        </form>
                    </Surface>
                )}

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Comissões da Câmara</SurfaceTitle>
                        <SurfaceDescription>
                            {activeCount} ativa(s) de {committees.length}
                        </SurfaceDescription>
                    </SurfaceHeader>
                    {committees.length === 0 ? (
                        <EmptyState
                            icon={UsersGroupTwoRoundedIcon}
                            title="Nenhuma comissão cadastrada"
                            description={
                                canManage
                                    ? 'Cadastre a primeira comissão no formulário acima.'
                                    : 'As comissões cadastradas pela Secretaria legislativa aparecem aqui.'
                            }
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Comissão</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Presidência</TableHead>
                                    <TableHead className="text-right">
                                        Membros
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Documentos
                                    </TableHead>
                                    <TableHead>Situação</TableHead>
                                    <TableHead className="text-right">
                                        Ações
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {committees.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <Link
                                                href={tenantUrl(
                                                    `/comissoes/${item.id}`,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {item.name}
                                            </Link>
                                            {item.acronym && (
                                                <p className="text-xs text-muted-foreground">
                                                    {item.acronym}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>{item.type_label}</TableCell>
                                        <TableCell>
                                            {item.president ?? (
                                                <span className="text-muted-foreground">
                                                    Não definida
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {item.members_count}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {item.documents_count}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    item.status === 'ativa'
                                                        ? 'success'
                                                        : 'outline'
                                                }
                                            >
                                                {item.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-2">
                                                <TableActionButton
                                                    asChild
                                                    label={`Visualizar ${item.name}`}
                                                >
                                                    <Link
                                                        href={tenantUrl(
                                                            `/comissoes/${item.id}`,
                                                        )}
                                                    >
                                                        <EyeIcon aria-hidden="true" />
                                                    </Link>
                                                </TableActionButton>
                                                {canManage && (
                                                    <>
                                                        <TableActionButton
                                                            asChild
                                                            label={`Editar ${item.name}`}
                                                        >
                                                            <Link
                                                                href={tenantUrl(
                                                                    `/comissoes/${item.id}/editar`,
                                                                )}
                                                            >
                                                                <PenIcon aria-hidden="true" />
                                                            </Link>
                                                        </TableActionButton>
                                                        <DeleteRecordButton
                                                            url={tenantUrl(
                                                                `/comissoes/${item.id}`,
                                                            )}
                                                            label={`Excluir ${item.name}`}
                                                            title="Excluir comissão?"
                                                            subject={item.name}
                                                            subjectDetail={
                                                                item.acronym
                                                            }
                                                            description="A comissão sai da lista da Câmara. Os documentos vinculados continuam na Base de Conhecimento."
                                                        />
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

CommitteesIndex.layout = {
    breadcrumbs: [{ title: 'Comissões', href: '/comissoes' }],
};
