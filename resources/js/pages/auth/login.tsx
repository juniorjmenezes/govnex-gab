import { Form, Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { FieldError } from '@/components/forms/field-error';
import { AltArrowDownIcon, ShieldUserIcon } from '@/components/icons';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { checkRequiredFormFields } from '@/lib/required-fields';
import type { InertiaFormRef } from '@/lib/required-fields';
import { cn } from '@/lib/utils';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

/**
 * A entrada padrão é o SSO do Govnex Hub
 * (docs/INTEGRACAO_GOVNEX_HUB.md, decisões #1 e #2). O acesso por senha
 * continua existindo, recolhido, porque é o que segura a administração da
 * plataforma quando o Hub estiver fora do ar (decisões #6 e #10) — e só conta
 * root consegue usá-lo: `Fortify::authenticateUsing` recusa as demais.
 *
 * O botão do Hub é uma âncora, não um `Link` do Inertia: o destino é uma
 * navegação de página inteira para outro domínio.
 */
const HUB_REDIRECT_URL = '/auth/hub/redirect';

type Props = {
    status?: string;
    canResetPassword: boolean;
    hubEnabled?: boolean;
};

export default function Login({
    status,
    canResetPassword,
    hubEnabled = false,
}: Props) {
    const formRef = useRef<InertiaFormRef>(null);
    const [localOpen, setLocalOpen] = useState(!hubEnabled);

    return (
        <>
            <Head title="Entrar" />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-emerald-700 dark:text-emerald-400">
                    {status}
                </div>
            )}

            <div className="space-y-4">
                {hubEnabled && (
                    <div className="space-y-3">
                        <Button asChild className="w-full">
                            <a href={HUB_REDIRECT_URL}>
                                <ShieldUserIcon
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Entrar com Govnex Hub
                            </a>
                        </Button>

                        <p className="text-center text-xs text-muted-foreground">
                            Sua conta, sua senha e seus vínculos ficam no Govnex
                            Hub.
                        </p>
                    </div>
                )}

                <Collapsible open={localOpen} onOpenChange={setLocalOpen}>
                    {hubEnabled && (
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full justify-between text-xs font-medium text-muted-foreground"
                            >
                                Acesso local de emergência
                                <AltArrowDownIcon
                                    className={cn(
                                        'size-4 transition-transform',
                                        localOpen && 'rotate-180',
                                    )}
                                    aria-hidden="true"
                                />
                            </Button>
                        </CollapsibleTrigger>
                    )}

                    <CollapsibleContent className="pt-2">
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
                                            autoFocus={!hubEnabled}
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
                                            aria-invalid={Boolean(
                                                errors.password,
                                            )}
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
                                            variant={
                                                hubEnabled
                                                    ? 'outline'
                                                    : 'default'
                                            }
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
                                                variant="ghost"
                                                className="w-full"
                                            >
                                                <Link
                                                    href={request()}
                                                    tabIndex={5}
                                                >
                                                    Esqueci minha senha
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </Form>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </>
    );
}

Login.layout = {
    title: 'Efetue login para entrar...',
};
