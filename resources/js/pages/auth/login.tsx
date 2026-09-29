import { Head, Link } from '@inertiajs/react';
import { ShieldUserIcon } from '@/components/icons';
import { LocalLoginForm } from '@/components/local-login-form';
import { Button } from '@/components/ui/button';

/**
 * A entrada padrão é o SSO do Govnex Hub
 * (docs/INTEGRACAO_GOVNEX_HUB.md, decisões #1 e #2). O acesso por senha
 * continua existindo, à parte, porque é o que segura a administração da
 * plataforma quando o Hub estiver fora do ar (decisões #6 e #10) — e só conta
 * root consegue usá-lo: `Fortify::authenticateUsing` recusa as demais.
 *
 * Com o Hub configurado, o acesso local vira um botão para a página própria
 * `/login/local` (ver `routes/web.php`) em vez de um trecho recolhido: um
 * `Collapsible` aqui mudava a altura da página de forma inconsistente ao
 * abrir. Sem o Hub configurado, esse é o único caminho de entrada, então o
 * formulário aparece direto, sem precisar de um botão para chegar nele.
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
    return (
        <>
            <Head title="Entrar" />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-emerald-700 dark:text-emerald-400">
                    {status}
                </div>
            )}

            {hubEnabled ? (
                <div className="space-y-3">
                    <Button asChild className="w-full uppercase">
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

                    <Button asChild variant="outline" className="w-full">
                        <Link href="/login/local">
                            Acesso local de emergência
                        </Link>
                    </Button>
                </div>
            ) : (
                <LocalLoginForm canResetPassword={canResetPassword} />
            )}
        </>
    );
}

Login.layout = {
    title: 'Entrar na sua conta',
    description: 'Use o Govnex Hub ou o acesso local para continuar.',
};
