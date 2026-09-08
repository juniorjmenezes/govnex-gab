import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// tailwind-merge não conhece as chaves customizadas do nosso @theme (ex.:
// --text-badge). Sem isso, "text-badge" cai no grupo de cor de texto (que
// aceita qualquer palavra após "text-") e é descartado por "conflitar" com
// classes como text-primary-foreground. Registre aqui qualquer novo
// utilitário de tema customizado que siga o padrão text-{nome}.
const twMerge = extendTailwindMerge({
    extend: {
        theme: {
            text: ['badge'],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}
