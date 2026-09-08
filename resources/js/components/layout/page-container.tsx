import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function PageContainer({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8',
                className,
            )}
            {...props}
        />
    );
}
