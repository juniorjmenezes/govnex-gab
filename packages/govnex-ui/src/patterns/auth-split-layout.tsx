import type { ReactNode } from 'react';

import { cn } from '../lib/utils';

export interface AuthSplitLayoutProps {
    title: string;
    /** Frase curta sob o título (contexto, não instrução longa). */
    description?: string;
    /** Formulário da página — cada app decide o próprio conteúdo. */
    children: ReactNode;
    /** Marca do produto (logo + nome), exibida no topo do painel escuro. */
    brand: ReactNode;
    /** Ação no canto superior direito da coluna de formulário (ex.: link de cadastro). */
    headerAction?: ReactNode;
    /** Frase de efeito curta, logo abaixo da marca. */
    tagline?: string;
    /** Par de blocos de destaque no rodapé do painel (título curto + descrição). */
    panelFooter?: ReactNode;
    /** Texto pequeno no rodapé da coluna de formulário (ex.: copyright). */
    formFooter?: ReactNode;
    className?: string;
}

/**
 * Tela cheia dividida em duas colunas: formulário à esquerda e um painel de
 * marca à direita, ocupando toda a altura — sem card, sem sombra, a própria
 * página é a divisão (inspirado em studio-admin.arhamkhnz.com/auth/v2/login).
 * O leve padding externo é só para separar o painel escuro das bordas da
 * janela (cantos arredondados nos quatro lados); do lado claro ele é
 * imperceptível, porque o fundo é o mesmo do restante da página. O painel
 * some abaixo de `lg`; nesse caso a coluna do formulário ocupa a tela
 * inteira. Conteúdo específico de cada app (marca, frase, rodapés) entra por
 * prop — o padrão não fixa nada de texto ou cor de marca.
 */
export function AuthSplitLayout({
    title,
    description,
    children,
    brand,
    headerAction,
    tagline,
    panelFooter,
    formFooter,
    className,
}: AuthSplitLayoutProps) {
    return (
        <div
            data-slot="auth-split-layout"
            className={cn('flex min-h-svh w-full bg-background p-2', className)}
        >
            <div className="flex flex-1 flex-col px-6 py-10 sm:px-10 lg:px-16">
                {headerAction && (
                    <div className="text-right text-sm">{headerAction}</div>
                )}
                <div className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center">
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                    <div className="mt-8">{children}</div>
                </div>
                {formFooter && (
                    <div className="mx-auto w-full max-w-sm pt-10 text-xs text-muted-foreground">
                        {formFooter}
                    </div>
                )}
            </div>
            <div
                className={cn(
                    'hidden shrink-0 flex-col justify-between overflow-hidden rounded-3xl bg-sidebar-header p-10 text-sidebar-header-foreground',
                    'lg:flex lg:w-[42%]',
                )}
            >
                <div className="flex flex-col items-start gap-2">
                    {brand}
                    {tagline && (
                        <p className="text-sm text-sidebar-header-foreground/80">
                            {tagline}
                        </p>
                    )}
                </div>
                {panelFooter && (
                    <div className="grid grid-cols-2 gap-6">{panelFooter}</div>
                )}
            </div>
        </div>
    );
}
