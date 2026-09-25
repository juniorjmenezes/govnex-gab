import type * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

export function AppContent({
    children,
    className,
    ...props
}: React.ComponentProps<'main'>) {
    return (
        <SidebarInset className={cn('bg-background', className)} {...props}>
            {children}
        </SidebarInset>
    );
}
