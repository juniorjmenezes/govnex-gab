import { isValidElement, useEffect, useRef, useState } from 'react';
import type { ComponentProps, PointerEvent, ReactNode } from 'react';

import { QuestionMarkIcon } from '../icons';
import { cn } from '../lib/utils';
import { Button } from './button';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverTrigger,
} from './popover';

export const surfaceClasses = 'rounded-2xl bg-card ring-1 ring-foreground/10';

type SurfaceTag = 'div' | 'section' | 'aside' | 'article';

export function Surface({
    as: Tag = 'div',
    className,
    ...props
}: ComponentProps<'div'> & { as?: SurfaceTag }) {
    return (
        <Tag
            data-slot="surface"
            className={cn(surfaceClasses, className)}
            {...props}
        />
    );
}

export function SurfaceHeader({
    actions,
    help,
    className,
    children,
    ...props
}: ComponentProps<'div'> & { actions?: ReactNode; help?: ReactNode }) {
    return (
        <div
            data-slot="surface-header"
            className={cn(
                'flex min-h-18 flex-col justify-center gap-1 border-b p-4 sm:flex-row sm:items-center sm:justify-between',
                !actions &&
                    help &&
                    'flex-row items-center justify-between gap-3',
                className,
            )}
            {...props}
        >
            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5 sm:flex-nowrap">
                {children}
            </div>
            {help ? (
                <div className="flex shrink-0 items-center justify-end gap-2">
                    {actions}
                    <SurfaceHelp>{help}</SurfaceHelp>
                </div>
            ) : (
                actions
            )}
        </div>
    );
}

export function SurfaceTitle({
    as: Tag = 'h2',
    className,
    ...props
}: ComponentProps<'h2'> & { as?: 'h2' | 'h3' }) {
    return (
        <Tag
            data-slot="surface-title"
            className={cn(
                'shrink-0 text-sm font-semibold tracking-wide text-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function SurfaceDescription({
    className,
    children,
    ...props
}: ComponentProps<'p'>) {
    const text = textOf(children);

    return (
        <>
            <span
                className="hidden text-muted-foreground/60 sm:inline"
                aria-hidden="true"
            >
                &middot;
            </span>
            <p
                data-slot="surface-description"
                title={text || undefined}
                className={cn(
                    'basis-full text-xs text-muted-foreground sm:min-w-0 sm:basis-auto sm:truncate',
                    className,
                )}
                {...props}
            >
                {children}
            </p>
        </>
    );
}

function SurfaceHelp({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const openedByHover = useRef(false);
    const closeTimer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(closeTimer.current), []);

    const onPointerEnter = (event: PointerEvent) => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        window.clearTimeout(closeTimer.current);

        if (!open) {
            openedByHover.current = true;
            setOpen(true);
        }
    };

    const onPointerLeave = (event: PointerEvent) => {
        if (event.pointerType !== 'mouse' || !openedByHover.current) {
            return;
        }

        closeTimer.current = window.setTimeout(() => {
            openedByHover.current = false;
            setOpen(false);
        }, 120);
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                openedByHover.current = false;
                setOpen(next);
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    className="border-border/70 bg-transparent text-muted-foreground hover:border-primary/40 hover:bg-primary/10 hover:text-primary aria-expanded:border-primary/40 aria-expanded:bg-primary/10 aria-expanded:text-primary dark:hover:bg-primary/15 dark:hover:text-[color-mix(in_oklch,var(--primary),white_35%)] dark:aria-expanded:bg-primary/15 dark:aria-expanded:text-[color-mix(in_oklch,var(--primary),white_35%)]"
                    aria-label="Sobre este card"
                    onPointerEnter={onPointerEnter}
                    onPointerLeave={onPointerLeave}
                    onClick={(event) => {
                        if (openedByHover.current) {
                            event.preventDefault();
                            openedByHover.current = false;
                        }
                    }}
                >
                    <QuestionMarkIcon aria-hidden="true" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={6}
                onOpenAutoFocus={(event) => event.preventDefault()}
                onPointerEnter={onPointerEnter}
                onPointerLeave={onPointerLeave}
            >
                <PopoverDescription className="mt-0 text-xs">
                    {children}
                </PopoverDescription>
            </PopoverContent>
        </Popover>
    );
}

function textOf(node: ReactNode): string {
    if (typeof node === 'string' || typeof node === 'number') {
        return String(node);
    }

    if (Array.isArray(node)) {
        return node.map(textOf).join('');
    }

    if (isValidElement<{ children?: ReactNode }>(node)) {
        return textOf(node.props.children);
    }

    return '';
}
