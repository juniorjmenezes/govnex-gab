import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface } from '@/components/ui/surface';
import type { Auth } from '@/types';

type Invitation = {
    entidade: string;
    gabinete: string | null;
    email: string;
    expires_at: string;
    existing_user: boolean;
};

export default function InvitationShow({
    credential,
    invitation,
}: {
    credential: string;
    invitation: Invitation;
}) {
    const auth = usePage<{ auth?: Auth }>().props.auth;
    const form = useForm({
        name: '',
        password: '',
        password_confirmation: '',
    });
    const serverErrors = form.errors as typeof form.errors & {
        invitation?: string;
        email?: string;
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/convites/entidade/${encodeURIComponent(credential)}`);
    };

    return (
        <>
            <Head title="Convite para entidade" />
            <div className="space-y-5">
                <Surface className="p-4 text-sm">
                    <p>
                        <strong>{invitation.entidade}</strong>
                        {invitation.gabinete ? ` · ${invitation.gabinete}` : ''}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Convite destinado a {invitation.email}
                    </p>
                </Surface>

                {invitation.existing_user && !auth?.user ? (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Este e-mail já possui uma conta. Entre com ela para
                            confirmar o novo vínculo.
                        </p>
                        <Button className="w-full" asChild>
                            <Link href="/login">Entrar e continuar</Link>
                        </Button>
                    </div>
                ) : (
                    <form className="space-y-4" onSubmit={submit}>
                        {!invitation.existing_user && (
                            <>
                                <div>
                                    <Label htmlFor="name">Nome</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError message={form.errors.name} />
                                </div>
                                <div>
                                    <Label htmlFor="password">Senha</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        minLength={12}
                                        value={form.data.password}
                                        onChange={(event) =>
                                            form.setData(
                                                'password',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={form.errors.password}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="password-confirmation">
                                        Confirme a senha
                                    </Label>
                                    <Input
                                        id="password-confirmation"
                                        type="password"
                                        minLength={12}
                                        value={form.data.password_confirmation}
                                        onChange={(event) =>
                                            form.setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </div>
                            </>
                        )}
                        <InputError message={serverErrors.invitation} />
                        <InputError message={serverErrors.email} />
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={form.processing}
                        >
                            Aceitar convite
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}

InvitationShow.layout = {
    title: 'Convite para o GOVNEX GAB',
    description: 'Revise o vínculo antes de acessar a entidade.',
};
