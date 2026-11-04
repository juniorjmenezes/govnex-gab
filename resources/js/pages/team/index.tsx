import { Head, router, usePage } from '@inertiajs/react';
import { ActivityMark } from '@/components/common/activity-mark';
import { HubManagedHint } from '@/components/common/hub-managed-hint';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
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
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';

/**
 * Equipe do gabinete. Pessoas e vínculos vêm do Govnex Hub; o que a pessoa
 * faz aqui é o cargo local (sem cargo, ela não acessa o gabinete).
 */
type Member = {
    id: number;
    name: string;
    email: string;
    position: string | null;
    is_active: boolean;
    account_active: boolean;
    last_login_at: string | null;
    created_at: string;
};
type PositionOption = { value: string; label: string };

const NO_POSITION_GUIDANCE =
    'Sem cargo, a pessoa não acessa o gabinete. Peça ao vereador ou ao chefe de gabinete para definir o cargo; se ninguém do gabinete tem cargo ainda, o primeiro é concedido pelo operador do sistema.';

export default function Team({
    members,
    canAssignPositions,
    positionOptions,
    positionLabels,
    currentUserId,
    isRoot,
}: {
    members: Member[];
    canAssignPositions: boolean;
    positionOptions: PositionOption[];
    positionLabels: Record<string, string>;
    currentUserId: number;
    isRoot: boolean;
}) {
    const tenantUrl = useTenantUrl();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const positionLabel = (value: string) => positionLabels[value] ?? value;
    // Regras do servidor espelhadas na tela: ninguém mexe no próprio cargo e
    // só a equipe GOVNEX (root) mexe no cargo de vereador.
    const editable = (member: Member) =>
        canAssignPositions &&
        (isRoot ||
            (member.id !== currentUserId && member.position !== 'VEREADOR'));
    const withoutPosition = members.filter(
        (member) => member.position === null,
    ).length;
    const updatePosition = (member: Member, value: string) =>
        router.put(
            tenantUrl(`/equipe/${member.id}/cargo`),
            { cargo: value === '' ? null : value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Equipe" />
            <PageContainer>
                <PageHeader
                    title="Equipe"
                    description="Consulte os integrantes do gabinete e o cargo de cada um."
                />
                {errors.cargo && (
                    <Alert variant="destructive">
                        <AlertTitle>Cargo não alterado</AlertTitle>
                        <AlertDescription>{errors.cargo}</AlertDescription>
                    </Alert>
                )}
                {withoutPosition > 0 && (
                    <Alert variant="warning">
                        <AlertTitle>
                            {withoutPosition === 1
                                ? '1 integrante sem cargo'
                                : `${withoutPosition} integrantes sem cargo`}
                        </AlertTitle>
                        <AlertDescription>
                            {canAssignPositions
                                ? 'Sem cargo, a pessoa não acessa o gabinete. Defina o cargo de cada integrante na coluna Cargo.'
                                : NO_POSITION_GUIDANCE}
                        </AlertDescription>
                    </Alert>
                )}
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
                                    <TableHead>Cargo</TableHead>
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
                                        <TableCell>
                                            {editable(member) ? (
                                                <AppSelect
                                                    value={
                                                        member.position ?? ''
                                                    }
                                                    onValueChange={(value) =>
                                                        updatePosition(
                                                            member,
                                                            value,
                                                        )
                                                    }
                                                    options={positionOptions}
                                                    emptyLabel="Sem cargo"
                                                    placeholder="Sem cargo"
                                                    clearable={false}
                                                    aria-label={`Cargo de ${member.name}`}
                                                    className="w-48"
                                                />
                                            ) : member.position ? (
                                                positionLabel(member.position)
                                            ) : (
                                                <Badge variant="warning">
                                                    Sem cargo
                                                </Badge>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                    <div className={cn(surfaceClasses, 'overflow-hidden')}>
                        <SurfaceHeader>
                            <SurfaceTitle>Novo integrante</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="p-5">
                            <HubManagedHint text="Pessoas e vínculos são geridos no Govnex Hub. Para adicionar ou remover um integrante, acesse o Hub; o cargo é definido aqui, na Equipe." />
                        </div>
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

Team.layout = {
    breadcrumbs: [{ title: 'Equipe', href: '/equipe' }],
};
