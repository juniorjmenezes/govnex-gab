import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Entrar" />

            <PasskeyVerify />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <FieldGroup>
                        <Field>
                            <FieldLabel htmlFor="email">E-mail</FieldLabel>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="nome@gabinete.gov.br"
                            />
                            <InputError message={errors.email} />
                        </Field>

                        <Field>
                            <div className="flex items-center">
                                <FieldLabel htmlFor="password">
                                    Senha
                                </FieldLabel>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="ml-auto text-sm"
                                        tabIndex={5}
                                    >
                                        Esqueci minha senha
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Sua senha"
                            />
                            <InputError message={errors.password} />
                        </Field>

                        <div className="flex items-center space-x-3">
                            <Switch
                                id="remember"
                                name="remember"
                                tabIndex={3}
                            />
                            <Label htmlFor="remember">
                                Manter acesso neste dispositivo
                            </Label>
                        </div>

                        <Field>
                            <Button
                                type="submit"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Entrar
                            </Button>
                        </Field>
                    </FieldGroup>
                )}
            </Form>

            {status && (
                <div className="text-center text-sm font-medium text-primary">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Acesse o GOVNEX GAB',
    description: 'Entre com as credenciais fornecidas pelo seu gabinete.',
};
