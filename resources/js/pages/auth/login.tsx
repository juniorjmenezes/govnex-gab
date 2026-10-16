import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { ShieldUserIcon } from '@/components/icons';
import { LocalLoginForm } from '@/components/local-login-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * A entrada padrão é o SSO do Govnex Hub
 * (docs/INTEGRACAO_GOVNEX_HUB.md, decisões #1 e #2). O acesso por senha
 * continua existindo, à parte, porque é o que segura a administração da
 * plataforma quando o Hub estiver fora do ar (decisões #6 e #10) — e só conta
 * root consegue usá-lo: `Fortify::authenticateUsing` recusa as demais.
 *
 * Com o Hub configurado, o acesso local vira um modal aberto a partir de um
 * botão, em vez de uma página própria: menos navegação para um fluxo raro
 * (Hub indisponível) e nenhuma rota/`Inertia::render` extra para manter em
 * sincronia com a tela principal. Sem o Hub configurado, esse é o único
 * caminho de entrada, então o formulário aparece direto, sem precisar de um
 * botão para chegar nele.
 *
 * O botão do Hub e o link de troca de conta são âncoras, não `Link` do
 * Inertia: o destino é uma navegação de página inteira para outro domínio.
 */
const HUB_REDIRECT_URL = '/auth/hub/redirect';
const HUB_TROCAR_CONTA_URL = '/auth/hub/redirect?trocar=1';

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
    const [acessoLocalAberto, setAcessoLocalAberto] = useState(false);

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
                        Hub.{' '}
                        <a
                            href={HUB_TROCAR_CONTA_URL}
                            className="underline underline-offset-2 hover:text-foreground"
                        >
                            Trocar de conta
                        </a>
                    </p>

                    <Button
                        variant="outline"
                        className="w-full"
                        onClick={() => setAcessoLocalAberto(true)}
                    >
                        Acesso local de emergência
                    </Button>

                    <Dialog
                        open={acessoLocalAberto}
                        onOpenChange={setAcessoLocalAberto}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    Acesso local de emergência
                                </DialogTitle>
                                <DialogDescription>
                                    Restrito à conta root, para quando o
                                    Govnex Hub estiver indisponível.
                                </DialogDescription>
                            </DialogHeader>

                            <LocalLoginForm
                                canResetPassword={canResetPassword}
                            />
                        </DialogContent>
                    </Dialog>
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
