import type { ComponentProps, ReactNode } from 'react';

import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '../components/surface';
import { cn } from '../lib/utils';

export interface SectionCardProps extends Omit<ComponentProps<'div'>, 'title'> {
    title: ReactNode;
    /** Contexto curto ao lado do título (contagem, período, território). */
    description?: ReactNode;
    /** Ações à direita do cabeçalho. */
    actions?: ReactNode;
    /** Explicação longa: vira o botão de ajuda do cabeçalho. */
    help?: ReactNode;
    /** Nível do título (padrão `h2`). */
    titleAs?: 'h2' | 'h3';
    /** Classes do corpo (padrão `p-4`; passe `p-0` para tabelas). */
    contentClassName?: string;
    interactive?: boolean;
}

/**
 * Atalho para o cartão de seção padrão: `Surface` + `SurfaceHeader`
 * (título `text-base`, descrição curta, ações e ajuda) + corpo. Não substitui
 * a composição manual quando o cartão precisa de outra estrutura.
 */
export function SectionCard({
    title,
    description,
    actions,
    help,
    titleAs = 'h2',
    contentClassName,
    interactive,
    className,
    children,
    ...props
}: SectionCardProps) {
    return (
        <Surface
            as="section"
            interactive={interactive}
            className={cn('flex min-w-0 flex-col', className)}
            {...props}
        >
            <SurfaceHeader actions={actions} help={help}>
                <SurfaceTitle as={titleAs}>{title}</SurfaceTitle>
                {description && (
                    <SurfaceDescription>{description}</SurfaceDescription>
                )}
            </SurfaceHeader>
            <div
                data-slot="section-card-content"
                className={cn('min-w-0 flex-1 p-4', contentClassName)}
            >
                {children}
            </div>
        </Surface>
    );
}
