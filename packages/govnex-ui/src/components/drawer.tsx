import { Drawer as DrawerPrimitive } from '@base-ui/react/drawer';
import * as React from 'react';

import { cn } from '../lib/utils';

function Drawer(props: React.ComponentProps<typeof DrawerPrimitive.Root>) {
    return <DrawerPrimitive.Root data-slot="drawer" {...props} />;
}

function DrawerTrigger(
    props: React.ComponentProps<typeof DrawerPrimitive.Trigger>,
) {
    return <DrawerPrimitive.Trigger data-slot="drawer-trigger" {...props} />;
}

function DrawerClose(
    props: React.ComponentProps<typeof DrawerPrimitive.Close>,
) {
    return <DrawerPrimitive.Close data-slot="drawer-close" {...props} />;
}

const drawerViewportSideClasses = {
    right: 'items-stretch justify-end p-4',
    left: 'items-stretch justify-start p-4',
    top: 'items-start',
    bottom: 'items-end',
} as const;

const drawerPopupSideClasses = {
    right: 'h-full w-full max-w-md rounded-2xl data-closed:slide-out-to-right-8 data-open:slide-in-from-right-8',
    left: 'h-full w-full max-w-md rounded-2xl data-closed:slide-out-to-left-8 data-open:slide-in-from-left-8',
    top: 'w-full max-h-[85vh] rounded-b-2xl data-closed:slide-out-to-top-8 data-open:slide-in-from-top-8',
    bottom: 'w-full max-h-[85vh] rounded-t-2xl data-closed:slide-out-to-bottom-8 data-open:slide-in-from-bottom-8',
} as const;

type DrawerSide = keyof typeof drawerViewportSideClasses;

function DrawerContent({
    className,
    children,
    side = 'right',
    ...props
}: React.ComponentProps<typeof DrawerPrimitive.Popup> & {
    side?: DrawerSide;
}) {
    return (
        <DrawerPrimitive.Portal>
            <DrawerPrimitive.Backdrop
                data-slot="drawer-backdrop"
                className="fixed inset-0 z-50 bg-black/20 duration-100 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0"
            />
            <DrawerPrimitive.Viewport
                className={cn(
                    'fixed inset-0 z-50 flex',
                    drawerViewportSideClasses[side],
                )}
            >
                <DrawerPrimitive.Popup
                    data-slot="drawer-content"
                    className={cn(
                        'flex flex-col bg-popover text-popover-foreground shadow-md ring-1 ring-foreground/10 duration-200 outline-none data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0',
                        drawerPopupSideClasses[side],
                        className,
                    )}
                    {...props}
                >
                    {children}
                </DrawerPrimitive.Popup>
            </DrawerPrimitive.Viewport>
        </DrawerPrimitive.Portal>
    );
}

function DrawerHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="drawer-header"
            className={cn('flex flex-col p-8', className)}
            {...props}
        />
    );
}

function DrawerFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="drawer-footer"
            className={cn('mt-auto flex flex-col gap-2 p-8', className)}
            {...props}
        />
    );
}

function DrawerTitle({
    className,
    ...props
}: React.ComponentProps<typeof DrawerPrimitive.Title>) {
    return (
        <DrawerPrimitive.Title
            data-slot="drawer-title"
            className={cn(
                'text-sm font-semibold tracking-wide text-foreground',
                className,
            )}
            {...props}
        />
    );
}

function DrawerDescription({
    className,
    ...props
}: React.ComponentProps<typeof DrawerPrimitive.Description>) {
    return (
        <DrawerPrimitive.Description
            data-slot="drawer-description"
            className={cn('text-xs text-muted-foreground', className)}
            {...props}
        />
    );
}

export {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
};
