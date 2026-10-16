import type { ReactNode } from 'react';

import { cn } from '../lib/utils';

export interface AuthCardLayoutProps {
    title?: string;
    /** Frase curta sob o título (contexto, não instrução longa). */
    description?: ReactNode;
    /** Formulário da página — cada app decide o próprio conteúdo. */
    children: ReactNode;
    /** Marca do produto (logo, e opcionalmente nome ao lado), no topo da coluna esquerda. */
    logo: ReactNode;
    /** Texto curto fixado ao final da coluna esquerda (ex.: frase de efeito, blocos de ajuda). */
    footer?: ReactNode;
    /** Texto pequeno sob o cartão (ex.: copyright). */
    copyright?: ReactNode;
    className?: string;
}

/**
 * Cartão flutuante centralizado, no estilo da tela de login do Google: logo
 * + título + descrição de um lado, formulário do outro, dentro de um único
 * cartão sobre o fundo — sem o painel de marca em tela cheia que o
 * `AuthSplitLayout` usa. Mesmo container (tamanho, raios, altura mínima de
 * conteúdo) em todos os produtos Govnex; conteúdo de marca (logo, frase,
 * rodapés) entra por prop, igual ao `AuthSplitLayout`.
 */
export function AuthCardLayout({
    title,
    description,
    children,
    logo,
    footer,
    copyright,
    className,
}: AuthCardLayoutProps) {
    return (
        <div
            data-slot="auth-card-layout"
            className={cn(
                'flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6',
                className,
            )}
        >
            <div className="w-full max-w-4xl overflow-hidden rounded-3xl bg-card">
                <div className="grid gap-10 p-8 sm:p-10 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] md:p-12">
                    <div className="flex flex-col gap-3">
                        {logo}
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight text-card-foreground">
                            {title}
                        </h1>
                        {description && (
                            <div className="text-sm text-muted-foreground">
                                {description}
                            </div>
                        )}
                        {footer && (
                            <div className="mt-auto pt-6 text-xs text-muted-foreground/70">
                                {footer}
                            </div>
                        )}
                    </div>
                    <div className="flex flex-col justify-between md:min-h-[22rem]">
                        {children}
                    </div>
                </div>
            </div>
            {copyright && (
                <p className="text-xs text-muted-foreground">{copyright}</p>
            )}
        </div>
    );
}
