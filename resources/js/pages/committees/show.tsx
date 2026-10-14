import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { DestructiveAlertDialog } from '@/components/common/destructive-alert-dialog';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { FieldError } from '@/components/forms/field-error';
import {
    DocumentTextIcon,
    PenIcon,
    UnlinkIcon,
    UsersGroupTwoRoundedIcon,
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
import { Label } from '@/components/ui/label';
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
import { formatDayMonthYear } from '@/lib/dates';
import { hasModule } from '@/lib/modules';
import type {
    Auth,
    CommitteeDocument,
    CommitteeManagementOptions,
    CommitteeMember,
    CommitteeRole,
    CommitteeShowProps,
} from '@/types';

/** Cargos de condução em destaque; membro e suplente ficam discretos. */
function roleBadgeVariant(
    role: CommitteeRole,
): 'info' | 'outline' | 'secondary' {
    if (role === 'membro') {
        return 'outline';
    }

    return role === 'suplente' ? 'secondary' : 'info';
}

/**
 * Página da comissão: composição e documentos. Leitura para toda a Câmara;
 * a Secretaria legislativa inclui membros e vincula documentos por
 * formulários em linha no topo de cada bloco.
 */
export default function CommitteeShow({
    committee,
    members,
    documents,
    canManage,
    options,
}: CommitteeShowProps) {
    const tenantUrl = useTenantUrl();
    const [editing, setEditing] = useState<CommitteeMember | null>(null);
    const manage = canManage && options !== null ? options : null;
    const isActive = committee.status === 'ativa';

    return (
        <>
            <Head title={committee.name} />
            <PageContainer>
                <PageHeader
                    title={committee.name}
                    description={[
                        committee.acronym,
                        `Comissão ${committee.type_label.toLowerCase()}`,
                        committee.closed_at
                            ? `extinta em ${formatDayMonthYear(committee.closed_at)}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <>
                            <Badge variant={isActive ? 'success' : 'outline'}>
                                {committee.status_label}
                            </Badge>
                            {canManage && (
                                <Button asChild variant="outline">
                                    <Link
                                        href={tenantUrl(
                                            `/comissoes/${committee.id}/editar`,
                                        )}
                                    >
                                        <PenIcon aria-hidden="true" />
                                        Editar comissão
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Membros</SurfaceTitle>
                        <SurfaceDescription>
                            {members.length} gabinete(s)
                        </SurfaceDescription>
                    </SurfaceHeader>
                    {manage && isActive && (
                        <AddMemberForm
                            committeeId={committee.id}
                            options={manage}
                        />
                    )}
                    {members.length === 0 ? (
                        <EmptyState
                            icon={UsersGroupTwoRoundedIcon}
                            title="Nenhum membro"
                            description="Os gabinetes de vereador que compõem a comissão aparecem aqui."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Cargo</TableHead>
                                    <TableHead>Gabinete</TableHead>
                                    <TableHead>Indicado</TableHead>
                                    <TableHead>Período</TableHead>
                                    {manage && (
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell>
                                            <Badge
                                                variant={roleBadgeVariant(
                                                    member.role,
                                                )}
                                            >
                                                {member.role_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <p className="font-medium">
                                                {member.office.leader ||
                                                    member.office.name}
                                            </p>
                                            {member.office.leader && (
                                                <p className="text-xs text-muted-foreground">
                                                    {member.office.name}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {member.user?.name ?? (
                                                <span className="text-muted-foreground">
                                                    Titular do gabinete
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {member.left_at
                                                ? `${formatDayMonthYear(member.joined_at)} a ${formatDayMonthYear(member.left_at)}`
                                                : `Desde ${formatDayMonthYear(member.joined_at)}`}
                                        </TableCell>
                                        {manage && (
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <TableActionButton
                                                        label={`Editar ${member.office.name}`}
                                                        onClick={() =>
                                                            setEditing(member)
                                                        }
                                                    >
                                                        <PenIcon aria-hidden="true" />
                                                    </TableActionButton>
                                                    <DeleteRecordButton
                                                        url={tenantUrl(
                                                            `/comissoes/${committee.id}/membros/${member.id}`,
                                                        )}
                                                        label={`Remover ${member.office.name}`}
                                                        title="Remover membro?"
                                                        subject={
                                                            member.office
                                                                .leader ||
                                                            member.office.name
                                                        }
                                                        subjectDetail={
                                                            member.role_label
                                                        }
                                                        description="O gabinete deixa de compor a comissão. Ele pode ser incluído de novo depois."
                                                    />
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader help="Documentos compartilhados com a Câmara na Base de Conhecimento. Para incluir um novo, envie-o lá com a opção de compartilhar com a Câmara e depois vincule aqui.">
                        <SurfaceTitle>Documentos</SurfaceTitle>
                        <SurfaceDescription>
                            {documents.length} vinculado(s)
                        </SurfaceDescription>
                    </SurfaceHeader>
                    {manage && (
                        <LinkDocumentForm
                            committeeId={committee.id}
                            options={manage}
                        />
                    )}
                    {documents.length === 0 ? (
                        <EmptyState
                            icon={DocumentTextIcon}
                            title="Nenhum documento vinculado"
                            description="Pautas, pareceres e atas da comissão aparecem aqui."
                        />
                    ) : (
                        <DocumentsTable
                            committeeId={committee.id}
                            documents={documents}
                            canManage={manage !== null}
                        />
                    )}
                </Surface>
            </PageContainer>

            {manage && (
                <EditMemberDialog
                    committeeId={committee.id}
                    member={editing}
                    options={manage}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function AddMemberForm({
    committeeId,
    options,
}: {
    committeeId: number;
    options: CommitteeManagementOptions;
}) {
    const tenantUrl = useTenantUrl();
    const form = useForm<{
        gabinete_id: string;
        usuario_id: string;
        cargo: CommitteeRole;
    }>({ gabinete_id: '', usuario_id: '', cargo: 'membro' });
    const available = options.offices.filter((office) => !office.is_member);
    const office = options.offices.find(
        (item) => String(item.id) === form.data.gabinete_id,
    );

    if (available.length === 0) {
        return (
            <p className="border-b p-4 text-sm text-muted-foreground">
                Todos os gabinetes de vereador da Câmara já fazem parte da
                comissão.
            </p>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            gabinete_id:
                data.gabinete_id === '' ? null : Number(data.gabinete_id),
            usuario_id: data.usuario_id === '' ? null : Number(data.usuario_id),
            cargo: data.cargo,
        }));
        form.post(tenantUrl(`/comissoes/${committeeId}/membros`), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-start"
        >
            <div className="w-full flex-1 space-y-1">
                <AppSelect
                    aria-label="Gabinete de vereador"
                    placeholder="Gabinete de vereador"
                    value={form.data.gabinete_id}
                    onValueChange={(value) =>
                        form.setData((data) => ({
                            ...data,
                            gabinete_id: value,
                            usuario_id: '',
                        }))
                    }
                    options={available.map((item) => ({
                        value: String(item.id),
                        label: item.leader
                            ? `${item.leader} · ${item.name}`
                            : item.name,
                    }))}
                />
                <FieldError message={form.errors.gabinete_id} />
            </div>
            <div className="w-full space-y-1 sm:w-56">
                <AppSelect
                    aria-label="Pessoa indicada"
                    placeholder="Titular do gabinete"
                    emptyLabel="Titular do gabinete"
                    value={form.data.usuario_id}
                    onValueChange={(value) => form.setData('usuario_id', value)}
                    options={(office?.people ?? []).map((person) => ({
                        value: String(person.id),
                        label: person.name,
                    }))}
                    disabled={office === undefined}
                />
                <FieldError message={form.errors.usuario_id} />
            </div>
            <div className="w-full space-y-1 sm:w-40">
                <AppSelect
                    aria-label="Cargo na comissão"
                    value={form.data.cargo}
                    onValueChange={(value) =>
                        form.setData(
                            'cargo',
                            (value || 'membro') as CommitteeRole,
                        )
                    }
                    options={options.roles}
                    clearable={false}
                />
                <FieldError message={form.errors.cargo} />
            </div>
            <Button
                className="w-full shrink-0 sm:w-auto"
                disabled={form.processing}
            >
                Incluir membro
            </Button>
        </form>
    );
}

function LinkDocumentForm({
    committeeId,
    options,
}: {
    committeeId: number;
    options: CommitteeManagementOptions;
}) {
    const tenantUrl = useTenantUrl();
    const form = useForm<{ documento_id: string; tipo_vinculo: string }>({
        documento_id: '',
        tipo_vinculo: '',
    });

    if (options.documents.length === 0) {
        return (
            <p className="border-b p-4 text-sm text-muted-foreground">
                Nenhum documento compartilhado com a Câmara disponível para
                vincular.
            </p>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            documento_id:
                data.documento_id === '' ? null : Number(data.documento_id),
            tipo_vinculo: data.tipo_vinculo === '' ? null : data.tipo_vinculo,
        }));
        form.post(tenantUrl(`/comissoes/${committeeId}/documentos`), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-start"
        >
            <div className="w-full flex-1 space-y-1">
                <AppSelect
                    aria-label="Documento da Base de Conhecimento"
                    placeholder="Documento da Base de Conhecimento"
                    value={form.data.documento_id}
                    onValueChange={(value) =>
                        form.setData('documento_id', value)
                    }
                    options={options.documents}
                />
                <FieldError message={form.errors.documento_id} />
            </div>
            <div className="w-full space-y-1 sm:w-44">
                <AppSelect
                    aria-label="Tipo do documento"
                    placeholder="Tipo"
                    emptyLabel="Sem tipo"
                    value={form.data.tipo_vinculo}
                    onValueChange={(value) =>
                        form.setData('tipo_vinculo', value)
                    }
                    options={options.link_types}
                />
                <FieldError message={form.errors.tipo_vinculo} />
            </div>
            <Button
                className="w-full shrink-0 sm:w-auto"
                disabled={form.processing}
            >
                Vincular documento
            </Button>
        </form>
    );
}

function DocumentsTable({
    committeeId,
    documents,
    canManage,
}: {
    committeeId: number;
    documents: CommitteeDocument[];
    canManage: boolean;
}) {
    const tenantUrl = useTenantUrl();
    const { auth } = usePage<{ auth: Auth }>().props;
    // O documento abre na Base de Conhecimento do próprio gabinete; sem o
    // módulo, fica só o título.
    const canOpen = hasModule(auth.modules, 'BASE_CONHECIMENTO');
    const [target, setTarget] = useState<CommitteeDocument | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const unlink = () => {
        if (target === null) {
            return;
        }

        router.delete(
            tenantUrl(`/comissoes/${committeeId}/documentos/${target.id}`),
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => setTarget(null),
            },
        );
    };

    return (
        <>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Documento</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>Vinculado em</TableHead>
                        {canManage && (
                            <TableHead className="text-right">Ações</TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {documents.map((document) => (
                        <TableRow key={document.id}>
                            <TableCell>
                                {canOpen ? (
                                    <Link
                                        href={tenantUrl(
                                            `/conhecimento/${document.id}`,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {document.title}
                                    </Link>
                                ) : (
                                    <p className="font-medium">
                                        {document.title}
                                    </p>
                                )}
                                {document.description && (
                                    <p className="line-clamp-1 text-xs text-muted-foreground">
                                        {document.description}
                                    </p>
                                )}
                            </TableCell>
                            <TableCell>
                                {document.link_type_label ?? (
                                    <span className="text-muted-foreground">
                                        Não informado
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                {formatDayMonthYear(document.linked_at)}
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    <div className="flex justify-end gap-2">
                                        <TableActionButton
                                            variant="destructive"
                                            label={`Desvincular ${document.title}`}
                                            onClick={() => setTarget(document)}
                                        >
                                            <UnlinkIcon aria-hidden="true" />
                                        </TableActionButton>
                                    </div>
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <DestructiveAlertDialog
                open={target !== null}
                onOpenChange={(open) => !open && setTarget(null)}
                title="Desvincular documento?"
                description="O documento deixa de aparecer na comissão, mas continua na Base de Conhecimento."
                subject={target?.title}
                subjectDetail={target?.link_type_label ?? undefined}
                icon={UnlinkIcon}
                submitting={submitting}
                confirmLabel={submitting ? 'Desvinculando...' : 'Desvincular'}
                onConfirm={unlink}
            />
        </>
    );
}

function EditMemberDialog({
    committeeId,
    member,
    options,
    onClose,
}: {
    committeeId: number;
    member: CommitteeMember | null;
    options: CommitteeManagementOptions;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={member !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {member && (
                    <EditMemberForm
                        key={member.id}
                        committeeId={committeeId}
                        member={member}
                        options={options}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditMemberForm({
    committeeId,
    member,
    options,
    onClose,
}: {
    committeeId: number;
    member: CommitteeMember;
    options: CommitteeManagementOptions;
    onClose: () => void;
}) {
    const tenantUrl = useTenantUrl();
    const form = useForm<{ usuario_id: string; cargo: CommitteeRole }>({
        usuario_id: member.user ? String(member.user.id) : '',
        cargo: member.role,
    });
    const people =
        options.offices.find((office) => office.id === member.office.id)
            ?.people ?? [];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            usuario_id: data.usuario_id === '' ? null : Number(data.usuario_id),
            cargo: data.cargo,
        }));
        form.put(tenantUrl(`/comissoes/${committeeId}/membros/${member.id}`), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form noValidate onSubmit={submit} className="grid gap-4">
            <DialogHeader>
                <DialogTitle>Editar membro</DialogTitle>
                <DialogDescription>
                    {member.office.leader
                        ? `${member.office.leader} · ${member.office.name}`
                        : member.office.name}
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="member-person">Pessoa indicada</Label>
                <AppSelect
                    id="member-person"
                    placeholder="Titular do gabinete"
                    emptyLabel="Titular do gabinete"
                    value={form.data.usuario_id}
                    onValueChange={(value) => form.setData('usuario_id', value)}
                    options={people.map((person) => ({
                        value: String(person.id),
                        label: person.name,
                    }))}
                />
                <FieldError message={form.errors.usuario_id} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="member-role">Cargo na comissão</Label>
                <AppSelect
                    id="member-role"
                    value={form.data.cargo}
                    onValueChange={(value) =>
                        form.setData(
                            'cargo',
                            (value || 'membro') as CommitteeRole,
                        )
                    }
                    options={options.roles}
                    clearable={false}
                />
                <FieldError message={form.errors.cargo} />
            </div>
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancelar
                </Button>
                <Button disabled={form.processing}>Salvar</Button>
            </DialogFooter>
        </form>
    );
}

CommitteeShow.layout = (page: CommitteeShowProps) => ({
    breadcrumbs: [
        { title: 'Comissões', href: '/comissoes' },
        { title: page.committee.name, href: '#' },
    ],
});
