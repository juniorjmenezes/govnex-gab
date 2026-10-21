import { AppLogoMark, AppWordmark } from '@/components/app-logo-mark';
import type { AuthLayoutProps } from '@/types';
import { AuthCardLayout as SharedAuthCardLayout } from '@govnex/ui';

/**
 * Cartão flutuante centralizado (`AuthCardLayout` da `@govnex/ui`), mesmo
 * container do Govnex Hub — usada só pela tela de login (ver
 * `auth-layout.tsx`); as demais telas de autenticação seguem no
 * `auth-simple-layout`. Conteúdo de marca (logo) é só do GAB — o padrão
 * compartilhado não fixa nada disso.
 *
 * Deliberadamente enxuto: sem tagline nem blocos de ajuda/primeiro acesso —
 * a tela inteira era indistinguível da do Hub além da cor. `AppWordmark` ao
 * lado da marca é a identificação do sistema que faltava (antes só no rodapé
 * minúsculo do copyright).
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
                <div className="flex items-center gap-2.5">
                    <AppLogoMark
                        className="block h-8 w-auto text-foreground"
                        aria-hidden="true"
                    />
                    <AppWordmark className="text-base font-semibold tracking-tight text-foreground" />
                </div>
            }
            copyright={`© ${new Date().getFullYear()} Govnex GAB`}
        >
            {children}
        </SharedAuthCardLayout>
    );
}
