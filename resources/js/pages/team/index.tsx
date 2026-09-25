import { Head } from '@inertiajs/react';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import { HubManagedHint } from '@/components/common/hub-managed-hint';
import { TableActionButton } from '@/components/common/table-action-button';
import { KeyIcon, TrashBinTrashIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Card } from '@/components/ui/card';
import {
    surfaceClasses,
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
import { accessRoleLabels } from '@/lib/access-roles';
import type { AccessRole } from '@/lib/access-roles';
import { cn } from '@/lib/utils';
type Member = {
    id: number;
    name: string;
    email: string;
    role: AccessRole;
    is_active: boolean;
    account_active: boolean;
    last_login_at: string | null;
    created_at: string;
};
type RoleOption = { value: string; label: string };
export default function Team({
    members,
    allowedRoles,
    canManage,
}: {
    members: Member[];
    allowedRoles: RoleOption[];
    canManage: boolean;
}) {
    const manageable = (member: Member) =>
        allowedRoles.some((role) => role.value === member.role);

    return (
        <>
            <Head title="Equipe" />
            <PageContainer>
                <PageHeader
                    title="Equipe"
                    description="Consulte os acessos, funções e situação dos integrantes do gabinete."
                />
                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <Card className="gap-0 overflow-hidden py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <span className="sr-only">
                                            Situação
                                        </span>
                                    </TableHead>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>Papel</TableHead>
                                    {canManage && (
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell className="w-10">
                                            <ActivityMark
                                                active={member.is_active}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <span className="block">
                                                {member.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {member.email}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <span className="block">
                                                {accessRoleLabels[member.role]}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {member.last_login_at
                                                    ? `Último acesso: ${new Date(
                                                          member.last_login_at,
                                                      ).toLocaleString(
                                                          'pt-BR',
                                                      )}`
                                                    : 'Ainda não acessou'}
                                            </span>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell>
                                                {manageable(member) && (
                                                    <div className="flex justify-end gap-2">
                                                        <TableActionButton
                                                            label={`Gerenciado no Govnex Hub — redefina a senha de ${member.name} lá`}
                                                            variant="outline"
                                                            disabled
                                                        >
                                                            <KeyIcon aria-hidden="true" />
                                                        </TableActionButton>
                                                        <ActivityToggleButton
                                                            active={
                                                                member.is_active
                                                            }
                                                            name={member.name}
                                                            disabled
                                                        />
                                                        <TableActionButton
                                                            label={`Gerenciado no Govnex Hub — remova ${member.name} lá`}
                                                            variant="destructive"
                                                            disabled
                                                        >
                                                            <TrashBinTrashIcon aria-hidden="true" />
                                                        </TableActionButton>
                                                    </div>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                    {canManage && (
                        <div className={cn(surfaceClasses, 'overflow-hidden')}>
                            <SurfaceHeader>
                                <SurfaceTitle>Novo integrante</SurfaceTitle>
                            </SurfaceHeader>
                            <div className="p-5">
                                <HubManagedHint text="Pessoas e vínculos são geridos no Govnex Hub. Para adicionar, editar, redefinir senha ou remover um integrante, acesse o Hub." />
                            </div>
                        </div>
                    )}
                </div>
            </PageContainer>
        </>
    );
}

Team.layout = {
    breadcrumbs: [{ title: 'Equipe', href: '/equipe' }],
};
