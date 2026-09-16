import type { ReactNode } from 'react';

import { cn } from '../lib/utils';

export interface PageHeaderProps {
    title: ReactNode;
    description?: string;
    actions?: ReactNode;
    className?: string;
}

export function PageHeader({
    title,
    description,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <h1 className="max-w-full min-w-0 font-bold text-foreground">
                    {title}
                </h1>
                {description && (
                    <>
                        <span
                            className="text-muted-foreground/60"
                            aria-hidden="true"
                        >
                            &middot;
                        </span>
                        <p className="text-xs text-muted-foreground">
                            {description}
                        </p>
                    </>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
