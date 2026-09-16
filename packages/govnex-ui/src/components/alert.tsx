import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '../lib/utils';

/*
 * Todas as variantes seguem a mesma receita, igual à dos badges: fundo
 * translúcido do tom semântico, borda fina de 1px no mesmo tom, ícone
 * saturado, título forte e descrição num tom intermediário da mesma cor
 * (nunca o cinza neutro, que fica "sujo" sobre fundo colorido). A
 * transparência mantém o contraste certo nos temas claro e escuro.
 *
 * O ícone (qualquer <svg> filho direto, em qualquer posição) fica numa faixa
 * fixa à direita, centralizado na vertical e separado do texto por um traço
 * interno de 1px (`after:`). Um <AlertAction> fica antes desse separador.
 */
const alertVariants = cva(
    [
        'group/alert relative grid w-full gap-0.5 rounded-md border px-4 py-3 text-left text-sm',
        // Faixa do ícone e separador interno.
        'has-[>svg]:pr-16 has-[>svg]:after:pointer-events-none has-[>svg]:after:absolute has-[>svg]:after:inset-y-2.5 has-[>svg]:after:right-12 has-[>svg]:after:w-px has-[>svg]:after:bg-current has-[>svg]:after:opacity-15',
        "*:[svg]:absolute *:[svg]:top-1/2 *:[svg]:right-3.5 *:[svg]:-translate-y-1/2 *:[svg:not([class*='size-'])]:size-5",
        // Espaço para a ação, com e sem ícone.
        'has-data-[slot=alert-action]:pr-16 has-[>svg]:has-data-[slot=alert-action]:pr-28',
    ],
    {
        variants: {
            variant: {
                default:
                    'border-border bg-muted/40 text-foreground *:data-[slot=alert-description]:text-muted-foreground *:[svg]:text-muted-foreground',
                warning:
                    'border-amber-500/25 bg-amber-500/10 text-amber-900 *:data-[slot=alert-description]:text-amber-900/75 dark:text-amber-100 dark:*:data-[slot=alert-description]:text-amber-100/70 *:[svg]:text-amber-600 dark:*:[svg]:text-amber-400',
                success:
                    'border-emerald-500/25 bg-emerald-500/10 text-emerald-900 *:data-[slot=alert-description]:text-emerald-900/75 dark:text-emerald-100 dark:*:data-[slot=alert-description]:text-emerald-100/70 *:[svg]:text-emerald-600 dark:*:[svg]:text-emerald-400',
                info: 'border-sky-500/25 bg-sky-500/10 text-sky-900 *:data-[slot=alert-description]:text-sky-900/75 dark:text-sky-100 dark:*:data-[slot=alert-description]:text-sky-100/70 *:[svg]:text-sky-600 dark:*:[svg]:text-sky-400',
                destructive:
                    'border-destructive/25 bg-destructive/8 text-destructive *:data-[slot=alert-description]:text-destructive/80 *:[svg]:text-destructive',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Alert({
    className,
    variant,
    ...props
}: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
    return (
        <div
            data-slot="alert"
            data-variant={variant ?? 'default'}
            role="alert"
            className={cn(alertVariants({ variant }), className)}
            {...props}
        />
    );
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-title"
            className={cn(
                'font-medium [&_a]:underline [&_a]:underline-offset-3',
                className,
            )}
            {...props}
        />
    );
}

function AlertDescription({
    className,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-description"
            className={cn(
                'text-sm text-pretty [&_a]:font-medium [&_a]:underline [&_a]:underline-offset-3 [&_p:not(:last-child)]:mb-2',
                className,
            )}
            {...props}
        />
    );
}

function AlertAction({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-action"
            className={cn(
                'absolute top-1/2 right-3 -translate-y-1/2 group-has-[>svg]/alert:right-15',
                className,
            )}
            {...props}
        />
    );
}

export { Alert, AlertAction, AlertDescription, AlertTitle };
