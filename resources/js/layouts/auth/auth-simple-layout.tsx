import { Link } from '@inertiajs/react';
import { AppLogoMark } from '@/components/app-logo-mark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Logo (em branco) fica num cabeçalho acima do cartão, não dentro dele — o
 * cartão só carrega título, descrição e o formulário em si. Antes, com a
 * marca completa dentro, o cartão ficava alto o bastante para gerar
 * rolagem vertical em telas mais baixas.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-primary p-6 md:p-10">
            <div className="w-full max-w-sm">
                <Link href={home()} className="mb-4 flex items-center px-1">
                    <AppLogoMark className="h-10 w-auto text-primary-foreground" />
                </Link>

                <div className="rounded-2xl bg-background p-8 shadow-xl">
                    <div className="flex flex-col gap-6">
                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-bold">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
