import type { ReactNode } from 'react';

import { cn } from '../lib/utils';
import type { IconComponent } from '../types/icon';

export interface ErrorPageProps {
    /** Código HTTP (403, 404, 419, 429, 500, 503...), exibido como rótulo curto. */
    status: number | string;
    title: string;
    description?: ReactNode;
    icon?: IconComponent;
    /** Botão(ões) de saída da página (ex.: voltar ao início, tentar de novo). */
    action?: ReactNode;
    /** Marca do produto (logo + nome), exibida acima do conteúdo. */
    brand?: ReactNode;
    className?: string;
}

/**
 * Tela cheia para erros HTTP (403/404/419/429/500/503...) — substitui as
 * páginas genéricas do Laravel por algo no padrão visual do app. Conteúdo
 * (textos, ícone, marca, ação) entra por prop; o padrão só fixa o layout.
 */
export function ErrorPage({
    status,
    title,
    description,
    icon: Icon,
    action,
    brand,
    className,
}: ErrorPageProps) {
    return (
        <div
            data-slot="error-page"
            className={cn(
                'flex min-h-svh w-full flex-col items-center justify-center gap-8 px-6 py-12 text-center',
                className,
            )}
        >
            {brand && <div>{brand}</div>}
            <div className="flex flex-col items-center gap-4">
                {Icon && (
                    <span
                        className="flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground ring-1 ring-foreground/5"
                        aria-hidden="true"
                    >
                        <Icon className="size-7" />
                    </span>
                )}
                <div className="space-y-1.5">
                    <p className="font-mono text-sm font-medium text-muted-foreground">
                        Erro {status}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight text-balance text-foreground">
                        {title}
                    </h1>
                    {description && (
                        <p className="max-w-md text-sm text-pretty text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            {action && (
                <div className="flex flex-wrap items-center justify-center gap-3">
                    {action}
                </div>
            )}
        </div>
    );
}
