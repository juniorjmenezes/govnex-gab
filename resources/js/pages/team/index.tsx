import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { TableActionButton } from '@/components/common/table-action-button';
import { FieldError } from '@/components/forms/field-error';
import { FieldLabel } from '@/components/forms/field-label';
import { KeyIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
type Member = {
    id: number;
    name: string;
    email: string;
    role: 'LIDER' | 'GESTOR' | 'MEMBRO';
    is_active: boolean;
    account_active: boolean;
    last_login_at: string | null;
    created_at: string;
};
type RoleOption = { value: string; label: string };
const schema = z
    .object({
        name: z.string().min(2, 'Informe o nome.'),
        email: z.email('E-mail inválido.'),
        role: z.string().min(1, 'Selecione a função.'),
        password: z
            .string()
            .refine(
                (value) => value === '' || value.length >= 12,
                'Use ao menos 12 caracteres para uma nova conta.',
            ),
        password_confirmation: z.string(),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ['password_confirmation'],
        message: 'As senhas não coincidem.',
    });
type Values = z.infer<typeof schema>;
const roleLabels: Record<Member['role'], string> = {
    LIDER: 'Líder',
    GESTOR: 'Gestor(a)',
    MEMBRO: 'Membro',
};
export default function Team({
    members,
    allowedRoles,
    canManage,
}: {
    members: Member[];
    allowedRoles: RoleOption[];
    canManage: boolean;
}) {
    const tenantUrl = useTenantUrl();
    const {
        control,
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            name: '',
            email: '',
            role: allowedRoles[0]?.value ?? '',
            password: '',
            password_confirmation: '',
        },
    });
    const submit = (values: Values) =>
        router.post(tenantUrl('/equipe'), values, {
            onSuccess: () => reset(),
            onError: (items) =>
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });
    const manageable = (member: Member) =>
        allowedRoles.some((role) => role.value === member.role);
    const toggle = (member: Member) =>
        router.put(
            tenantUrl(`/equipe/${member.id}`),
            {
                role: member.role,
                is_active: !member.is_active,
            },
            { preserveScroll: true },
        );
    const resetPassword = (member: Member) => {
        const password = window.prompt(
            `Nova senha para ${member.name} (mínimo de 12 caracteres):`,
        );

        if (password) {
            router.put(
                tenantUrl(`/equipe/${member.id}/senha`),
                { password, password_confirmation: password },
                { preserveScroll: true },
            );
        }
    };

    return (
        <>
            <Head title="Equipe" />
            <PageContainer>
                <PageHeader
                    title="Equipe"
                    description="Gerencie acessos, funções e situação dos integrantes do gabinete."
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
                                    <TableHead>Função</TableHead>
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
                                                {roleLabels[member.role]}
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
                                                            label={`Redefinir senha de ${member.name}`}
                                                            variant="outline"
                                                            onClick={() =>
                                                                resetPassword(
                                                                    member,
                                                                )
                                                            }
                                                        >
                                                            <KeyIcon aria-hidden="true" />
                                                        </TableActionButton>
                                                        <ActivityToggleButton
                                                            active={
                                                                member.is_active
                                                            }
                                                            name={member.name}
                                                            onClick={() =>
                                                                toggle(member)
                                                            }
                                                        />
                                                        <DeleteRecordButton
                                                            url={tenantUrl(
                                                                `/equipe/${member.id}`,
                                                            )}
                                                            label={`Excluir ${member.name}`}
                                                            title="Excluir integrante?"
                                                            description="O acesso será removido e o integrante deixará de aparecer na equipe. O histórico de atividades será preservado."
                                                        />
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
                        <form
                            noValidate
                            onSubmit={handleSubmit(submit)}
                            className={cn(surfaceClasses, 'overflow-hidden')}
                        >
                            <SurfaceHeader>
                                <SurfaceTitle>Novo integrante</SurfaceTitle>
                            </SurfaceHeader>
                            <div className="space-y-4 p-5">
                                <div className="space-y-1">
                                    <Label htmlFor="team-name">Nome</Label>
                                    <Input
                                        id="team-name"
                                        {...register('name')}
                                    />
                                    <FieldError
                                        message={errors.name?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="team-email">E-mail</Label>
                                    <Input
                                        id="team-email"
                                        type="email"
                                        {...register('email')}
                                    />
                                    <FieldError
                                        message={errors.email?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="team-role">Função</Label>
                                    <Controller
                                        control={control}
                                        name="role"
                                        render={({ field }) => (
                                            <AppSelect
                                                id="team-role"
                                                value={field.value}
                                                onValueChange={field.onChange}
                                                options={allowedRoles}
                                            />
                                        )}
                                    />
                                    <FieldError
                                        message={errors.role?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <FieldLabel
                                        htmlFor="team-password"
                                        help="Se o e-mail já possuir uma conta, deixe a senha em branco. O acesso existente será apenas vinculado a este gabinete."
                                    >
                                        Senha inicial (somente para nova conta)
                                    </FieldLabel>
                                    <Input
                                        id="team-password"
                                        type="password"
                                        {...register('password')}
                                    />
                                    <FieldError
                                        message={errors.password?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="team-password-confirmation">
                                        Confirmar senha
                                    </Label>
                                    <Input
                                        id="team-password-confirmation"
                                        type="password"
                                        {...register('password_confirmation')}
                                    />
                                    <FieldError
                                        message={
                                            errors.password_confirmation
                                                ?.message
                                        }
                                    />
                                </div>
                                <Button
                                    className="w-full"
                                    disabled={isSubmitting}
                                >
                                    Adicionar à equipe
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </PageContainer>
        </>
    );
}

Team.layout = {
    breadcrumbs: [{ title: 'Equipe', href: '/equipe' }],
};
