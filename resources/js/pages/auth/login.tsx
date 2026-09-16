import { Form, Head, Link } from '@inertiajs/react';
import { useRef } from 'react';
import { FieldError } from '@/components/forms/field-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { checkRequiredFormFields } from '@/lib/required-fields';
import type { InertiaFormRef } from '@/lib/required-fields';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const formRef = useRef<InertiaFormRef>(null);

    return (
        <>
            <Head title="Entrar" />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-emerald-700 dark:text-emerald-400">
                    {status}
                </div>
            )}

            <PasskeyVerify />

            <Form
                noValidate
                {...store.form()}
                ref={formRef}
                resetOnSuccess={['password']}
                onBefore={() =>
                    checkRequiredFormFields(formRef.current, {
                        email: 'Informe o e-mail.',
                        password: 'Informe a senha.',
                    })
                }
            >
                {({ processing, errors }) => (
                    <div className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="email">E-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                aria-required="true"
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="nome@gabinete.gov.br"
                                aria-invalid={Boolean(errors.email)}
                            />
                            <FieldError message={errors.email} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="password">Senha</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                aria-required="true"
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Sua senha"
                                aria-invalid={Boolean(errors.password)}
                            />
                            <FieldError message={errors.password} />
                        </div>

                        <label className="flex shrink-0 items-center gap-2 text-xs font-medium text-muted-foreground">
                            <Switch
                                id="remember"
                                name="remember"
                                size="sm"
                                tabIndex={3}
                            />
                            Ficar conectado
                        </label>

                        <div className="space-y-2">
                            <Button
                                type="submit"
                                className="w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Entrar
                            </Button>

                            {canResetPassword && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full"
                                >
                                    <Link href={request()} tabIndex={5}>
                                        Esqueci minha senha
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Efetue login para entrar...',
};
