import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { ShieldUserIcon } from '@/components/icons';
import { LocalLoginForm } from '@/components/local-login-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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
 * botão, em vez de ficar no próprio cartão: mantém o cartão principal curto
 * (sem a rolagem vertical que o conteúdo somado dos dois causava) e separa
 * claramente o caminho raro (Hub fora do ar) do caminho padrão. Sem o Hub
 * configurado, o formulário é o único caminho de entrada, então aparece
 * direto, sem precisar de um botão para chegar nele.
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

                    <Button asChild variant="outline" className="w-full">
                        <a href={HUB_TROCAR_CONTA_URL}>Trocar de conta</a>
                    </Button>

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
    title: 'Entrar no GOVNEX GAB',
    description: 'Use sua conta do Govnex Hub para continuar.',
};
