import type { ReactNode } from 'react';

import { cn } from '../lib/utils';

export interface PageHeaderProps {
    title: ReactNode;
    /** Frase curta sob o título (contexto, não instrução longa). */
    description?: ReactNode;
    /** Ações da página, alinhadas à direita do título em telas largas. */
    actions?: ReactNode;
    /** Trilha opcional acima do título (ex.: `<Breadcrumb>` em telas aninhadas). */
    breadcrumb?: ReactNode;
    className?: string;
}

/**
 * Cabeçalho de página empilhado: trilha opcional, título (`text-2xl`) e
 * descrição logo abaixo; ações à direita no desktop e abaixo no celular.
 */
export function PageHeader({
    title,
    description,
    actions,
    breadcrumb,
    className,
}: PageHeaderProps) {
    return (
        <div
            data-slot="page-header"
            className={cn('flex flex-col gap-3', className)}
        >
            {breadcrumb && <div className="min-w-0">{breadcrumb}</div>}
            {/* Quebra por espaço, não por breakpoint: as ações descem para baixo do
                título quando ele ficaria com menos de ~18rem (ex.: tablet com a
                sidebar aberta), em vez de espremer o título em duas linhas. */}
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
                <div className="flex min-w-[min(100%,18rem)] flex-1 flex-col gap-1">
                    <h1 className="max-w-full min-w-0 text-2xl font-semibold tracking-tight text-balance text-foreground">
                        {title}
                    </h1>
                    {description && (
                        <p className="max-w-3xl text-sm text-pretty text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
        </div>
    );
}
