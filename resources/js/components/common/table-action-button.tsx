import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

// Botões de ação de tabela mantêm o contorno (círculo) sempre visível;
// o preenchimento de cor só surge no hover/foco. Reaproveitado por botões
// de tabela que não passam pelo TableActionButton (ex.: linhas com texto).
export const tableButtonDestructiveHoverClass =
    'border-border bg-transparent text-foreground hover:border-destructive/40 hover:bg-destructive/15 hover:text-destructive dark:bg-transparent dark:hover:bg-destructive/25';
export const tableButtonOutlineHoverClass = 'bg-transparent';

type TableActionButtonProps = Omit<ComponentProps<typeof Button>, 'size'> & {
    label: string;
};

export function TableActionButton({
    label,
    children,
    variant = 'outline',
    className,
    ...props
}: TableActionButtonProps) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    size="icon-sm"
                    variant={variant}
                    aria-label={label}
                    className={cn(
                        variant === 'destructive' &&
                            tableButtonDestructiveHoverClass,
                        variant === 'outline' && tableButtonOutlineHoverClass,
                        className,
                    )}
                    {...props}
                >
                    {children}
                </Button>
            </TooltipTrigger>
            <TooltipContent side="top" sideOffset={6}>
                {label}
            </TooltipContent>
        </Tooltip>
    );
}
