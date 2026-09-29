import { AppLogoMark, AppWordmark } from '@/components/app-logo-mark';
import type { AuthLayoutProps } from '@/types';
import { AuthSplitLayout } from '@govnex/ui';

/**
 * Tela cheia dividida em duas colunas (`AuthSplitLayout` da `@govnex/ui`),
 * usada só pela tela de login (ver `auth-layout.tsx`); as demais telas de
 * autenticação seguem no `auth-simple-layout`. Conteúdo de marca (logo,
 * frase e blocos de destaque) é só do GAB — o padrão compartilhado não fixa
 * nada disso.
 */
export default function AuthCardLayout({
    children,
    title = '',
    description,
}: AuthLayoutProps) {
    return (
        <AuthSplitLayout
            title={title}
            description={description}
            brand={
                <div className="flex flex-col items-start gap-2">
                    <AppLogoMark className="h-8 w-auto" aria-hidden="true" />
                    <AppWordmark className="text-2xl font-semibold tracking-tight" />
                </div>
            }
            tagline="Gestão de gabinete legislativo, do jeito que devia ser."
            panelFooter={
                <>
                    <div>
                        <p className="text-sm font-medium">Precisa de ajuda?</p>
                        <p className="mt-1 text-xs text-sidebar-header-foreground/80">
                            Fale com o suporte do seu gabinete.
                        </p>
                    </div>
                    <div>
                        <p className="text-sm font-medium">Primeiro acesso?</p>
                        <p className="mt-1 text-xs text-sidebar-header-foreground/80">
                            Peça o convite a quem administra seu gabinete.
                        </p>
                    </div>
                </>
            }
            formFooter={`© ${new Date().getFullYear()} Govnex GAB`}
        >
            {children}
        </AuthSplitLayout>
    );
}
