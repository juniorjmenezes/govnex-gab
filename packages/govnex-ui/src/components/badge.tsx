import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import * as React from 'react';

import { cn } from '../lib/utils';

const badgeVariants = cva(
    'inline-flex w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-full border px-2.5 py-1 text-badge leading-none whitespace-nowrap transition-colors focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>svg]:pointer-events-none [&>svg]:size-3',
    {
        variants: {
            variant: {
                default:
                    'border-transparent bg-primary font-medium text-primary-foreground [a]:hover:bg-primary/90',
                secondary:
                    'border-transparent bg-secondary font-medium text-secondary-foreground [a]:hover:bg-secondary/90',
                destructive:
                    'border-transparent bg-destructive font-medium text-destructive-foreground focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 [a]:hover:bg-destructive/90',
                outline:
                    'border-border font-medium text-foreground [a]:hover:bg-accent [a]:hover:text-accent-foreground',
                // Variantes semânticas suaves (fundo `-soft`, texto no próprio
                // token): contraste ≥ 4,5:1 verificado por `contrast:check`.
                success:
                    'border-success/15 bg-success-soft font-medium text-success [a]:hover:bg-success/15',
                warning:
                    'border-warning/15 bg-warning-soft font-medium text-warning [a]:hover:bg-warning/15',
                info: 'border-info/15 bg-info-soft font-medium text-info [a]:hover:bg-info/15',
                danger: 'border-destructive/15 bg-destructive-soft font-medium text-destructive [a]:hover:bg-destructive/15',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Badge({
    className,
    variant = 'default',
    asChild = false,
    ...props
}: React.ComponentProps<'span'> &
    VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot.Root : 'span';

    return (
        <Comp
            data-slot="badge"
            data-variant={variant}
            className={cn(badgeVariants({ variant }), className)}
            {...props}
        />
    );
}

export { Badge, badgeVariants };
