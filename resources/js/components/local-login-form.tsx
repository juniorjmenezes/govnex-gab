import { Form, Link } from '@inertiajs/react';
import { useRef } from 'react';
import { FieldError } from '@/components/forms/field-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { checkRequiredFormFields } from '@/lib/required-fields';
import type { InertiaFormRef } from '@/lib/required-fields';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { AuthField, AuthRememberToggle } from '@govnex/ui';

/**
 * Passkey + formulário de e-mail/senha do acesso local (restrito a root, ver
 * `FortifyServiceProvider::configureAuthentication`). Reaproveitado pela tela
 * de login normal (quando o Hub não está configurado, esse é o único
 * caminho) e pela tela dedicada `/login/local` (quando o Hub está
 * configurado, o acesso local vira uma página própria, não um trecho
 * recolhido — recolher aumentava a altura da página de forma inconsistente).
 *
 * O campo de e-mail usa o `AuthField` compartilhado da `@govnex/ui` — mesmo
 * arranjo em todos os produtos Govnex. O de senha fica de fora porque só o
 * GAB tem `PasswordInput` (alternar mostrar/ocultar); o padrão compartilhado
 * não força esse recurso onde ele ainda não existe.
 */
export function LocalLoginForm({
    canResetPassword,
}: {
    canResetPassword: boolean;
}) {
    const formRef = useRef<InertiaFormRef>(null);

    return (
        <div className="space-y-4">
            <PasskeyVerify className="uppercase" />

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
                        <AuthField
                            id="email"
                            label="E-mail"
                            type="email"
                            name="email"
                            aria-required="true"
                            autoFocus
                            tabIndex={1}
                            autoComplete="email"
                            placeholder="nome@gabinete.gov.br"
                            error={errors.email}
                        />

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

                        <AuthRememberToggle name="remember" tabIndex={3} />

                        <div className="space-y-2">
                            <Button
                                type="submit"
                                className="w-full uppercase"
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
        </div>
    );
}
