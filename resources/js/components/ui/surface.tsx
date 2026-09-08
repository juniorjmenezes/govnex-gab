import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Aparência única de toda superfície elevada do sistema: cards, seções de
 * página e painéis. O primitivo Card consome as mesmas classes, de modo que
 * os dois não podem divergir.
 *
 * Use `surfaceClasses` diretamente quando o elemento precisar ser outra tag
 * (um <form>, por exemplo) ou já tiver comportamento próprio.
 */
export const surfaceClasses = 'rounded-2xl bg-card ring-1 ring-foreground/10';

type SurfaceTag = 'div' | 'section' | 'aside' | 'article';

export function Surface({
    as: Tag = 'div',
    className,
    ...props
}: ComponentProps<'div'> & { as?: SurfaceTag }) {
    return (
        <Tag
            data-slot="surface"
            className={cn(surfaceClasses, className)}
            {...props}
        />
    );
}
