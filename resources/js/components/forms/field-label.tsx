import type { ComponentProps, ReactNode } from 'react';
import { QuestionCircleIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverTrigger,
} from '@/components/ui/popover';

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
                        className="-my-1 ml-auto text-muted-foreground hover:text-foreground"
                        aria-label={`Ajuda sobre ${title.toLocaleLowerCase('pt-BR')}`}
                    >
                        <QuestionCircleIcon aria-hidden="true" />
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
