import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { KeyIcon, PowerIcon } from '@solar-icons/react/outline';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { TableActionButton } from '@/components/common/table-action-button';
import { FieldError } from '@/components/forms/field-error';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { surfaceClasses } from '@/components/ui/surface';
import { cn } from '@/lib/utils';

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
                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <Card className="gap-0 py-0">
                        <div className="divide-y">
                            {users.map((user) => (
                                <article
                                    key={user.id}
                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="font-medium">
                                                {user.name}
                                            </h2>
                                            <Badge
                                                variant={
                                                    user.is_active
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {user.is_active
                                                    ? 'Ativo'
                                                    : 'Inativo'}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {user.email}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Último acesso:{' '}
                                            {user.last_login_at
                                                ? new Date(
                                                      user.last_login_at,
                                                  ).toLocaleString('pt-BR')
                                                : 'ainda não acessou'}
                                        </p>
                                    </div>
                                    <div className="ml-auto flex justify-end gap-2">
                                        <TableActionButton
                                            label={`Redefinir senha de ${user.name}`}
                                            variant="outline"
                                            onClick={() => resetPassword(user)}
                                        >
                                            <KeyIcon aria-hidden="true" />
                                        </TableActionButton>
                                        <TableActionButton
                                            label={`${user.is_active ? 'Desativar' : 'Ativar'} ${user.name}`}
                                            variant={
                                                user.is_active
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                            onClick={() => toggle(user)}
                                        >
                                            <PowerIcon aria-hidden="true" />
                                        </TableActionButton>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </Card>
                    <form
                        onSubmit={handleSubmit(submit)}
                        className={cn(surfaceClasses, 'overflow-hidden')}
                    >
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Novo usuário root
                            </h2>
                        </div>
                        <div className="space-y-4 p-5">
                            <div className="space-y-1">
                                <Label htmlFor="root-name">Nome</Label>
                                <Input id="root-name" {...register('name')} />
                                <FieldError message={errors.name?.message} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="root-email">E-mail</Label>
                                <Input
                                    id="root-email"
                                    type="email"
                                    {...register('email')}
                                />
                                <FieldError message={errors.email?.message} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="root-password">
                                    Senha inicial
                                </Label>
                                <Input
                                    id="root-password"
                                    type="password"
                                    {...register('password')}
                                />
                                <FieldError
                                    message={errors.password?.message}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="root-password-confirmation">
                                    Confirmar senha
                                </Label>
                                <Input
                                    id="root-password-confirmation"
                                    type="password"
                                    {...register('password_confirmation')}
                                />
                                <FieldError
                                    message={
                                        errors.password_confirmation?.message
                                    }
                                />
                            </div>
                            <Button className="w-full" disabled={isSubmitting}>
                                Cadastrar usuário root
                            </Button>
                        </div>
                    </form>
                </div>
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
