import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import { TableActionButton } from '@/components/common/table-action-button';
import { FieldError } from '@/components/forms/field-error';
import { KeyIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
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

type RootUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string;
};

const schema = z
    .object({
        name: z.string().min(2, 'Informe o nome.'),
        email: z.email('E-mail inválido.'),
        password: z.string().min(12, 'Use ao menos 12 caracteres.'),
        password_confirmation: z.string(),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ['password_confirmation'],
        message: 'As senhas não coincidem.',
    });
type Values = z.infer<typeof schema>;

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

export default function RootUsers({ users }: { users: RootUser[] }) {
    const {
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
            password: '',
            password_confirmation: '',
        },
    });
    const submit = (values: Values) =>
        router.post('/admin/usuarios', values, {
            onSuccess: () => reset(),
            onError: (items) =>
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });
    const toggle = (user: RootUser) =>
        router.patch(
            `/admin/usuarios/${user.id}`,
            { is_active: !user.is_active },
            { preserveScroll: true },
        );
    const resetPassword = (user: RootUser) => {
        const password = window.prompt(
            `Nova senha para ${user.name} (mínimo de 12 caracteres):`,
        );

        if (password) {
            router.post(
                `/admin/usuarios/${user.id}/redefinir-senha`,
                { password, password_confirmation: password },
                { preserveScroll: true },
            );
        }
    };

    return (
        <>
            <Head title="Usuários root" />
            <PageContainer>
                <PageHeader
                    title="Usuários root"
                    description="Contas com acesso irrestrito a configurações que afetam o funcionamento de toda a plataforma."
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader help="A senha inicial precisa ter ao menos 12 caracteres. Oriente a pessoa a trocá-la no primeiro acesso, em Configurações da conta.">
                        <SurfaceTitle>Novo usuário root</SurfaceTitle>
                    </SurfaceHeader>
                    <form
                        noValidate
                        onSubmit={handleSubmit(submit)}
                        className="flex flex-col gap-3 p-4 lg:flex-row lg:items-start"
                    >
                        <div className="w-full min-w-0 space-y-1 lg:flex-1">
                            <Input
                                aria-label="Nome"
                                aria-required="true"
                                placeholder="Nome"
                                autoComplete="off"
                                {...register('name')}
                            />
                            <FieldError message={errors.name?.message} />
                        </div>
                        <div className="w-full min-w-0 space-y-1 lg:flex-1">
                            <Input
                                type="email"
                                aria-label="E-mail"
                                aria-required="true"
                                placeholder="E-mail"
                                autoComplete="off"
                                {...register('email')}
                            />
                            <FieldError message={errors.email?.message} />
                        </div>
                        <div className="w-full min-w-0 space-y-1 lg:flex-1">
                            <Input
                                type="password"
                                aria-label="Senha inicial"
                                aria-required="true"
                                placeholder="Senha inicial"
                                autoComplete="new-password"
                                {...register('password')}
                            />
                            <FieldError message={errors.password?.message} />
                        </div>
                        <div className="w-full min-w-0 space-y-1 lg:flex-1">
                            <Input
                                type="password"
                                aria-label="Confirmar senha"
                                aria-required="true"
                                placeholder="Confirmar senha"
                                autoComplete="new-password"
                                {...register('password_confirmation')}
                            />
                            <FieldError
                                message={errors.password_confirmation?.message}
                            />
                        </div>
                        <Button
                            className="w-full shrink-0 lg:w-auto"
                            disabled={isSubmitting}
                        >
                            Cadastrar usuário
                        </Button>
                    </form>
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Usuários</SurfaceTitle>
                        <SurfaceDescription>
                            {users.length === 1
                                ? '1 conta'
                                : `${users.length} contas`}
                        </SurfaceDescription>
                    </SurfaceHeader>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <span className="sr-only">Situação</span>
                                </TableHead>
                                <TableHead>Nome</TableHead>
                                <TableHead>E-mail</TableHead>
                                <TableHead>Último acesso</TableHead>
                                <TableHead className="w-px text-right">
                                    Ações
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.map((user) => (
                                <TableRow key={user.id}>
                                    <TableCell className="w-10">
                                        <ActivityMark active={user.is_active} />
                                    </TableCell>
                                    <TableCell>{user.name}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {user.email}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {user.last_login_at
                                            ? dateTime.format(
                                                  new Date(user.last_login_at),
                                              )
                                            : 'Ainda não acessou'}
                                    </TableCell>
                                    <TableCell className="w-px">
                                        <div className="flex justify-end gap-2">
                                            <TableActionButton
                                                label={`Redefinir senha de ${user.name}`}
                                                onClick={() =>
                                                    resetPassword(user)
                                                }
                                            >
                                                <KeyIcon aria-hidden="true" />
                                            </TableActionButton>
                                            <ActivityToggleButton
                                                active={user.is_active}
                                                name={user.name}
                                                onClick={() => toggle(user)}
                                            />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Surface>
            </PageContainer>
        </>
    );
}

RootUsers.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Usuários da plataforma', href: '/admin/usuarios' },
    ],
};
