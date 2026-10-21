import type { SVGProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Marca do GOVNEX. O traçado usa `currentColor`, então a cor vem da classe
 * de texto aplicada no ponto de uso (tema claro/escuro e cor da organização).
 */
export function AppLogoMark(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            viewBox="0 0 512 512"
            fill="currentColor"
            xmlns="http://www.w3.org/2000/svg"
            {...props}
        >
            <path d="M140.35,255.99c0,63.87,51.77,115.63,115.63,115.63v131.81c-136.66,0-247.42-110.79-247.42-247.44S119.33,8.57,255.98,8.57s247.44,110.77,247.44,247.42h-131.81c0-63.86-51.76-115.63-115.63-115.63s-115.63,51.77-115.63,115.63Z" />
            <path d="M503.44,487.25c0,5.44-.19,10.84-.56,16.18h-132.39c.74-5.29,1.13-10.69,1.13-16.18,0-63.86-51.77-115.63-115.63-115.63v-131.79c136.66,0,247.44,110.77,247.44,247.42Z" />
        </svg>
    );
}

/**
 * Nome do produto ao lado da marca. Some quando a sidebar recolhe para
 * ícones, deixando só `AppLogoMark`.
 */
export function AppWordmark({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'truncate text-sm font-medium tracking-tight group-data-[collapsible=icon]:hidden',
                className,
            )}
        >
            GOVNEX GAB
        </span>
    );
}
