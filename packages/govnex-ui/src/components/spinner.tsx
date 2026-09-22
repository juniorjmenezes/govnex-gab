import type { ComponentProps } from 'react';

import { cn } from '../lib/utils';

/**
 * Indicador de carregamento. É um traço girando, não um ícone com
 * significado, então vive como SVG local em vez de depender da biblioteca de
 * ícones — o Solar não traz um anel de loading.
 */
function Spinner({ className, ...props }: ComponentProps<'svg'>) {
    return (
        <svg
            data-slot="spinner"
            role="status"
            aria-label="Loading"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            className={cn('size-4 animate-spin', className)}
            {...props}
        >
            <circle cx="12" cy="12" r="9" className="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" />
        </svg>
    );
}

export { Spinner };
