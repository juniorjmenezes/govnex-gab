import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { FieldError } from '@/components/forms/field-error';
import { LetterIcon } from '@/components/icons';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { hasModule } from '@/lib/modules';
import {
    checkRequiredFields,
    checkRequiredFormFields,
} from '@/lib/required-fields';
import type { InertiaFormRef } from '@/lib/required-fields';
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
    const profileFormRef = useRef<InertiaFormRef>(null);
    const emailUnverified =
        mustVerifyEmail && auth.user.email_verified_at === null;

    return (
        <>
            <Head title="Perfil" />

            <Card className="gap-0 py-0">
                <SurfaceHeader help="O e-mail é usado para entrar no sistema e receber avisos da conta.">
                    <SurfaceTitle>Dados pessoais</SurfaceTitle>
                </SurfaceHeader>
                <Form
                    noValidate
                    {...ProfileController.update.form()}
                    ref={profileFormRef}
                    onBefore={() =>
                        checkRequiredFormFields(profileFormRef.current, {
                            name: 'Informe o nome.',
                            email: 'Informe o e-mail.',
                        })
                    }
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-5 p-5 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="name">
                                        Nome <span aria-hidden="true">*</span>
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={auth.user.name}
                                        aria-required="true"
                                        autoComplete="name"
                                        placeholder="Nome completo"
                                    />
                                    <FieldError message={errors.name} />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="email">
                                        E-mail <span aria-hidden="true">*</span>
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        defaultValue={auth.user.email}
                                        aria-required="true"
                                        autoComplete="username"
                                        placeholder="nome@gabinete.gov.br"
                                    />
                                    <FieldError message={errors.email} />
                                </div>

                                {emailUnverified && (
                                    <Alert
                                        variant={
                                            status === 'verification-link-sent'
                                                ? 'success'
                                                : 'warning'
                                        }
                                        className="md:col-span-2"
                                    >
                                        <LetterIcon />
                                        <AlertTitle>
                                            {status === 'verification-link-sent'
                                                ? 'Link de verificação enviado'
                                                : 'E-mail ainda não verificado'}
                                        </AlertTitle>
                                        <AlertDescription>
                                            {status ===
                                            'verification-link-sent' ? (
                                                'Confira sua caixa de entrada para confirmar o endereço.'
                                            ) : (
                                                <>
                                                    Confirme o endereço para
                                                    receber os avisos da conta.{' '}
                                                    <Link
                                                        href={send()}
                                                        as="button"
                                                    >
                                                        Reenviar e-mail de
                                                        verificação
                                                    </Link>
                                                </>
                                            )}
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>
                            <div className="flex justify-end gap-2 border-t p-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Salvar dados
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Card>

            {auth.user.gabinete_id !== null &&
                hasModule(auth.modules, 'WHATSAPP') && (
                    <Card className="gap-0 py-0">
                        <SurfaceHeader
                            actions={
                                whatsapp?.has_current_consent ? (
                                    <Badge variant="secondary">
                                        Consentimento {whatsappConsent.version}
                                    </Badge>
                                ) : undefined
                            }
                            help="O número é declarado pelo próprio usuário e não passa por validação de posse."
                        >
                            <SurfaceTitle>WhatsApp</SurfaceTitle>
                            <SurfaceDescription>
                                Notificações operacionais
                            </SurfaceDescription>
                        </SurfaceHeader>

                        {whatsapp?.has_current_consent ? (
                            <>
                                <div className="space-y-1 p-5">
                                    <p className="text-sm font-medium">
                                        Número com final {whatsapp.last_four}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        As notificações operacionais estão
                                        autorizadas para este número.
                                    </p>
                                </div>
                                <div className="flex justify-end gap-2 border-t p-4">
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        onClick={() =>
                                            router.delete(
                                                '/settings/whatsapp',
                                                {
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        Revogar consentimento
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <form
                                noValidate
                                onSubmit={(event) => {
                                    event.preventDefault();

                                    if (
                                        !checkRequiredFields(
                                            whatsappForm.data,
                                            whatsappForm,
                                            {
                                                telefone:
                                                    'Informe o número com DDD.',
                                            },
                                        )
                                    ) {
                                        return;
                                    }

                                    whatsappForm.post('/settings/whatsapp', {
                                        preserveScroll: true,
                                        onSuccess: () => whatsappForm.reset(),
                                    });
                                }}
                            >
                                <div className="grid gap-5 p-5 md:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label htmlFor="whatsapp-telefone">
                                            Número com DDD{' '}
                                            <span aria-hidden="true">*</span>
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
                                            aria-required="true"
                                            placeholder="(88) 99999-9999"
                                        />
                                        <FieldError
                                            message={
                                                whatsappForm.errors.telefone
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1 md:col-span-2">
                                        <Label className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3">
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium">
                                                    Aceite para notificações
                                                    operacionais
                                                </span>
                                                <span className="block text-xs font-normal text-muted-foreground">
                                                    {whatsappConsent.text}
                                                </span>
                                            </span>
                                            <Checkbox
                                                checked={
                                                    whatsappForm.data.aceite
                                                }
                                                onCheckedChange={(checked) =>
                                                    whatsappForm.setData(
                                                        'aceite',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </Label>
                                        <FieldError
                                            message={whatsappForm.errors.aceite}
                                        />
                                    </div>
                                </div>
                                <div className="flex justify-end gap-2 border-t p-4">
                                    <Button
                                        type="submit"
                                        disabled={whatsappForm.processing}
                                    >
                                        Cadastrar WhatsApp
                                    </Button>
                                </div>
                            </form>
                        )}
                    </Card>
                )}
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
