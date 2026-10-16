import { AppLogoMark } from '@/components/app-logo-mark';
import type { AuthLayoutProps } from '@/types';
import { AuthCardLayout as SharedAuthCardLayout } from '@govnex/ui';

/**
 * Cartão flutuante centralizado (`AuthCardLayout` da `@govnex/ui`), mesmo
 * container do Govnex Hub — usada só pela tela de login (ver
 * `auth-layout.tsx`); as demais telas de autenticação seguem no
 * `auth-simple-layout`. Conteúdo de marca (logo, frase e blocos de ajuda) é
 * só do GAB — o padrão compartilhado não fixa nada disso.
 */
export default function AuthCardLayout({
    children,
    title = '',
    description,
}: AuthLayoutProps) {
    return (
        <SharedAuthCardLayout
            title={title}
            description={description}
            logo={
                <AppLogoMark
                    className="block h-10 w-auto self-start text-foreground"
                    aria-hidden="true"
                />
            }
            footer={
                <div className="space-y-4">
                    <p>
                        Gestão de gabinete legislativo, do jeito que devia ser.
                    </p>
                    <div className="grid grid-cols-2 gap-6">
                        <div>
                            <p className="text-sm font-medium text-foreground">
                                Precisa de ajuda?
                            </p>
                            <p className="mt-1 text-xs">
                                Fale com o suporte do seu gabinete.
                            </p>
                        </div>
                        <div>
                            <p className="text-sm font-medium text-foreground">
                                Primeiro acesso?
                            </p>
                            <p className="mt-1 text-xs">
                                Peça o convite a quem administra seu gabinete.
                            </p>
                        </div>
                    </div>
                </div>
            }
            copyright={`© ${new Date().getFullYear()} Govnex GAB`}
        >
            {children}
        </SharedAuthCardLayout>
    );
}
