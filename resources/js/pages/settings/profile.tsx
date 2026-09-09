import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { hasModule } from '@/lib/modules';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
    whatsapp,
    whatsappConsent,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    whatsapp: {
        status: string;
        last_four: string | null;
        declared_at: string | null;
        has_current_consent: boolean;
    } | null;
    whatsappConsent: { version: string; text: string };
}) {
    const { auth } = usePage<PageProps>().props;
    const whatsappForm = useForm({ telefone: '', aceite: false });

    return (
        <>
            <Head title="Perfil" />

            <h1 className="sr-only">Perfil</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Dados pessoais"
                    description="Atualize seu nome e endereço de e-mail"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nome</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Nome completo"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    Endereço de e-mail
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Endereço de e-mail"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Seu endereço de e-mail ainda não foi
                                            verificado.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Salvar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            {auth.user.gabinete_id !== null &&
                hasModule(auth.modules, 'WHATSAPP') && (
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title="WhatsApp"
                            description="Gerencie o número usado para notificações operacionais"
                        />

                        {whatsapp?.has_current_consent ? (
                            <div className="space-y-4 rounded-lg border p-4">
                                <div>
                                    <p className="font-medium">
                                        Número declarado com final{' '}
                                        {whatsapp.last_four}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Consentimento {whatsappConsent.version}{' '}
                                        vigente. O número é declarado pelo
                                        usuário e não passa por validação de
                                        posse.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() =>
                                        router.delete('/settings/whatsapp', {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Revogar consentimento
                                </Button>
                            </div>
                        ) : (
                            <form
                                className="space-y-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    whatsappForm.post('/settings/whatsapp', {
                                        preserveScroll: true,
                                        onSuccess: () => whatsappForm.reset(),
                                    });
                                }}
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="whatsapp-telefone">
                                        Número com DDD
                                    </Label>
                                    <Input
                                        id="whatsapp-telefone"
                                        value={whatsappForm.data.telefone}
                                        onChange={(event) =>
                                            whatsappForm.setData(
                                                'telefone',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="tel"
                                        placeholder="(88) 99999-9999"
                                    />
                                    <InputError
                                        message={whatsappForm.errors.telefone}
                                    />
                                </div>
                                <div className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3">
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">
                                            Aceite para notificações
                                            operacionais
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {whatsappConsent.text}
                                        </span>
                                    </span>
                                    <Checkbox
                                        checked={whatsappForm.data.aceite}
                                        onCheckedChange={(checked) =>
                                            whatsappForm.setData(
                                                'aceite',
                                                checked === true,
                                            )
                                        }
                                        aria-label="Aceite para notificações operacionais"
                                    />
                                </div>
                                <InputError
                                    message={whatsappForm.errors.aceite}
                                />
                                <Button
                                    type="submit"
                                    disabled={whatsappForm.processing}
                                >
                                    Cadastrar WhatsApp
                                </Button>
                            </form>
                        )}
                    </div>
                )}

            {!['root', 'vereador'].includes(auth.user.role) && <DeleteUser />}
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Perfil',
            href: edit(),
        },
    ],
};
