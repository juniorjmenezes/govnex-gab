import type { ComponentProps, ReactNode } from 'react';

import { QuestionMarkIcon } from '../icons';
import { Button } from '../components/button';
import { Label } from '../components/label';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverTrigger,
} from '../components/popover';

type FieldLabelProps = ComponentProps<typeof Label> & {
    help?: ReactNode;
    helpTitle?: string;
};

export function FieldLabel({
    children,
    help,
    helpTitle,
    ...labelProps
}: FieldLabelProps) {
    if (!help) {
        return <Label {...labelProps}>{children}</Label>;
    }

    const title =
        helpTitle ?? (typeof children === 'string' ? children : 'Este campo');

    return (
        <div className="flex min-h-7 items-center justify-between gap-2">
            <Label {...labelProps}>{children}</Label>
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        className="-my-1 ml-auto text-muted-foreground hover:bg-primary/10 hover:text-primary aria-expanded:bg-primary/10 aria-expanded:text-primary dark:hover:bg-primary/15 dark:hover:text-[color-mix(in_oklch,var(--primary),white_35%)] dark:aria-expanded:bg-primary/15 dark:aria-expanded:text-[color-mix(in_oklch,var(--primary),white_35%)]"
                        aria-label={`Ajuda sobre ${title.toLocaleLowerCase('pt-BR')}`}
                    >
                        <QuestionMarkIcon aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" sideOffset={6}>
                    <PopoverDescription className="mt-0">
                        {help}
                    </PopoverDescription>
                </PopoverContent>
            </Popover>
        </div>
    );
}
