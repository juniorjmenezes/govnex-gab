import { AlertDialog as AlertDialogPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '../lib/utils';
import { Button } from './button';

function AlertDialog(
    props: React.ComponentProps<typeof AlertDialogPrimitive.Root>,
) {
    return <AlertDialogPrimitive.Root data-slot="alert-dialog" {...props} />;
}

function AlertDialogTrigger(
    props: React.ComponentProps<typeof AlertDialogPrimitive.Trigger>,
) {
    return (
        <AlertDialogPrimitive.Trigger
            data-slot="alert-dialog-trigger"
            {...props}
        />
    );
}

function AlertDialogPortal(
    props: React.ComponentProps<typeof AlertDialogPrimitive.Portal>,
) {
    return (
        <AlertDialogPrimitive.Portal
            data-slot="alert-dialog-portal"
            {...props}
        />
    );
}

function AlertDialogOverlay({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Overlay>) {
    return (
        <AlertDialogPrimitive.Overlay
            data-slot="alert-dialog-overlay"
            className={cn(
                'fixed inset-0 z-50 bg-black/20 duration-100 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0',
                className,
            )}
            {...props}
        />
    );
}

function AlertDialogContent({
    className,
    size = 'default',
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Content> & {
    size?: 'default' | 'sm';
}) {
    return (
        <AlertDialogPortal>
            <AlertDialogOverlay />
            <div className="pointer-events-none fixed inset-0 z-50 grid place-items-center">
                <AlertDialogPrimitive.Content
                    data-slot="alert-dialog-content"
                    data-size={size}
                    className={cn(
                        'group/alert-dialog-content pointer-events-auto grid w-full gap-6 rounded-md bg-popover p-6 text-popover-foreground shadow-md ring-1 ring-foreground/10 duration-100 outline-none data-[size=default]:max-w-xs data-[size=sm]:max-w-xs data-[size=default]:sm:max-w-md data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
                        className,
                    )}
                    {...props}
                />
            </div>
        </AlertDialogPortal>
    );
}

function AlertDialogHeader({
    className,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-dialog-header"
            className={cn(
                'grid gap-1 text-left has-data-[slot=alert-dialog-media]:grid-cols-[auto_1fr] has-data-[slot=alert-dialog-media]:gap-x-4',
                className,
            )}
            {...props}
        />
    );
}

function AlertDialogFooter({
    className,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-dialog-footer"
            className={cn(
                'flex flex-col-reverse gap-2 group-data-[size=sm]/alert-dialog-content:grid group-data-[size=sm]/alert-dialog-content:grid-cols-2 sm:flex-row sm:justify-end',
                className,
            )}
            {...props}
        />
    );
}

function AlertDialogMedia({
    className,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-dialog-media"
            className={cn(
                "row-span-2 inline-flex size-10 items-center justify-center rounded-md bg-muted *:[svg:not([class*='size-'])]:size-5",
                className,
            )}
            {...props}
        />
    );
}

function AlertDialogTitle({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Title>) {
    return (
        <AlertDialogPrimitive.Title
            data-slot="alert-dialog-title"
            className={cn(
                'text-sm leading-5 font-semibold tracking-wide text-foreground group-has-data-[slot=alert-dialog-media]/alert-dialog-content:col-start-2',
                className,
            )}
            {...props}
        />
    );
}

function AlertDialogDescription({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Description>) {
    return (
        <AlertDialogPrimitive.Description
            data-slot="alert-dialog-description"
            className={cn(
                'text-sm leading-5 text-muted-foreground group-has-data-[slot=alert-dialog-media]/alert-dialog-content:col-start-2 *:[a]:underline *:[a]:underline-offset-3 *:[a]:hover:text-foreground',
                className,
            )}
            {...props}
        />
    );
}

function AlertDialogAction({
    className,
    variant = 'default',
    size = 'default',
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Action> &
    Pick<React.ComponentProps<typeof Button>, 'variant' | 'size'>) {
    return (
        <Button variant={variant} size={size} asChild>
            <AlertDialogPrimitive.Action
                data-slot="alert-dialog-action"
                className={cn(className)}
                {...props}
            />
        </Button>
    );
}

function AlertDialogCancel({
    className,
    variant = 'outline',
    size = 'default',
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Cancel> &
    Pick<React.ComponentProps<typeof Button>, 'variant' | 'size'>) {
    return (
        <Button variant={variant} size={size} asChild>
            <AlertDialogPrimitive.Cancel
                data-slot="alert-dialog-cancel"
                className={cn(className)}
                {...props}
            />
        </Button>
    );
}

export {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogMedia,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogTitle,
    AlertDialogTrigger,
};
