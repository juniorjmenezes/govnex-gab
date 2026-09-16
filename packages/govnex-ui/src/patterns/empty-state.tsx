import type { ReactNode } from 'react';

import type { IconComponent } from '../types/icon';

export interface EmptyStateProps {
    icon: IconComponent;
    title: string;
    description: string;
    action?: ReactNode;
}

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: EmptyStateProps) {
    return (
        <div className="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <Icon
                className="mb-4 size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <h2 className="text-base font-semibold">{title}</h2>
            <p className="mt-1 max-w-md text-sm leading-6 text-muted-foreground">
                {description}
            </p>
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}
