import type { ComponentProps } from 'react';
import {
    DialogContent,
    DialogFooter,
    DialogHeader,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export function ScrollableDialogContent({
    className,
    ...props
}: ComponentProps<typeof DialogContent>) {
    return (
        <DialogContent
            className={cn(
                'max-h-[calc(100svh-1rem)] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 overflow-hidden p-0 sm:max-h-[min(90svh,52rem)]',
                className,
            )}
            {...props}
        />
    );
}

export function ScrollableDialogHeader({
    className,
    ...props
}: ComponentProps<typeof DialogHeader>) {
    return (
        <DialogHeader
            className={cn('mx-0 mt-0 shrink-0 border-b px-6 py-5', className)}
            {...props}
        />
    );
}

export function ScrollableDialogBody({
    className,
    ...props
}: ComponentProps<'div'>) {
    return (
        <div
            data-slot="dialog-scroll-body"
            className={cn(
                'min-h-0 [scrollbar-width:thin] [scrollbar-color:var(--border)_transparent] [scrollbar-gutter:stable] overflow-y-auto overscroll-contain px-6 py-5 [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-sm [&::-webkit-scrollbar-thumb]:bg-border [&::-webkit-scrollbar-track]:bg-transparent',
                className,
            )}
            {...props}
        />
    );
}

export function ScrollableDialogFooter({
    className,
    ...props
}: ComponentProps<typeof DialogFooter>) {
    return (
        <DialogFooter
            className={cn('shrink-0 border-t bg-popover px-6 py-4', className)}
            {...props}
        />
    );
}
