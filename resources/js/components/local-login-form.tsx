import { Form, Link } from '@inertiajs/react';
import { useRef } from 'react';
import PasskeyVerify from '@/components/passkey-verify';
import { Button } from '@/components/ui/button';
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
 * Os dois campos usam o `AuthField` compartilhado da `@govnex/ui` — mesmo
 * arranjo em todos os produtos Govnex; `type="password"` já traz o botão de
 * mostrar/ocultar embutido.
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

                        <AuthField
                            id="password"
                            label="Senha"
                            type="password"
                            name="password"
                            aria-required="true"
                            tabIndex={2}
                            autoComplete="current-password"
                            placeholder="Sua senha"
                            error={errors.password}
                        />

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
