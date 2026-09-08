import { AppLogoMark } from '@/components/app-logo-mark';
import { Card, CardContent } from '@/components/ui/card';
import type { AuthLayoutProps } from '@/types';

/**
 * Estrutura do bloco `login-04` do shadcn: card de duas colunas, com o
 * formulário à esquerda e um painel de marca à direita que só aparece a
 * partir de `md`. Usado apenas pela tela de login (ver `auth-layout.tsx`);
 * as demais telas de autenticação seguem no `auth-simple-layout`.
 *
 * O painel lateral do bloco original é uma `<img>` apontando para
 * `/placeholder.svg`. Sem arte para essa área, ele repete o cabeçalho da
 * sidebar — `AppLogoMark` mais o wordmark sobre `bg-sidebar-header` — em vez
 * de depender de um asset inexistente.
 */
export default function AuthCardLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-muted p-6 md:p-10">
            <div className="w-full max-w-sm md:max-w-4xl">
                <Card className="overflow-hidden p-0">
                    <CardContent className="grid p-0 md:grid-cols-2">
                        <div className="p-6 md:p-8">
                            <div className="flex flex-col gap-6">
                                <div className="flex flex-col items-center gap-2 text-center">
                                    <h1 className="text-2xl font-bold">
                                        {title}
                                    </h1>
                                    <p className="text-balance text-muted-foreground">
                                        {description}
                                    </p>
                                </div>
                                {children}
                            </div>
                        </div>
                        <div className="relative hidden bg-sidebar-header text-sidebar-header-foreground md:block">
                            <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 p-10">
                                <AppLogoMark
                                    className="h-24 w-auto"
                                    aria-hidden="true"
                                />
                                <span className="text-sm font-medium tracking-tight">
                                    GOVNEX.GAB
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
