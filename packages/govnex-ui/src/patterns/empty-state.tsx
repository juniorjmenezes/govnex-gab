import type { ReactNode } from 'react';

import { cn } from '../lib/utils';
import type { IconComponent } from '../types/icon';

export interface EmptyStateProps {
    icon: IconComponent;
    title: string;
    description?: ReactNode;
    /** Ação principal (ex.: `<Button>` para cadastrar o primeiro registro). */
    action?: ReactNode;
    /**
     * `default` ocupa uma área de página; `compact` cabe dentro de um cartão
     * (listas curtas do painel, por exemplo).
     */
    size?: 'default' | 'compact';
    className?: string;
}

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    size = 'default',
    className,
}: EmptyStateProps) {
    const compact = size === 'compact';

    return (
        <div
            data-slot="empty-state"
            className={cn(
                'flex flex-col items-center justify-center text-center',
                compact ? 'min-h-40 px-4 py-8' : 'min-h-64 px-6 py-12',
                className,
            )}
        >
            <span
                className={cn(
                    'mb-4 flex items-center justify-center rounded-xl bg-muted text-muted-foreground ring-1 ring-foreground/5',
                    compact ? 'size-10' : 'size-12',
                )}
                aria-hidden="true"
            >
                <Icon className={compact ? 'size-5' : 'size-6'} />
            </span>
            <h2
                className={cn(
                    'font-semibold text-foreground',
                    compact ? 'text-sm' : 'text-base',
                )}
            >
                {title}
            </h2>
            {description && (
                <p
                    className={cn(
                        'mt-1 max-w-md text-muted-foreground',
                        compact ? 'text-xs leading-5' : 'text-sm leading-6',
                    )}
                >
                    {description}
                </p>
            )}
            {action && (
                <div className={compact ? 'mt-4' : 'mt-5'}>{action}</div>
            )}
        </div>
    );
}
