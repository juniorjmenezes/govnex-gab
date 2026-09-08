import { HexColorInput, HexColorPicker } from 'react-colorful';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

interface ColorPickerProps {
    id?: string;
    value: string;
    onChange: (hex: string) => void;
    disabled?: boolean;
    'aria-label'?: string;
}

/**
 * Seletor de cor composto a partir de primitivos shadcn (Popover) + a
 * biblioteca headless react-colorful — o shadcn/ui não tem um componente
 * de color picker no registry oficial, então montamos o nosso: área de
 * matiz/saturação, prévia da cor e um campo para digitar o hexadecimal
 * diretamente, em vez do seletor nativo do sistema operacional.
 */
export function ColorPicker({
    id,
    value,
    onChange,
    disabled = false,
    'aria-label': ariaLabel,
}: ColorPickerProps) {
    return (
        <Popover>
            <PopoverTrigger asChild disabled={disabled}>
                <button
                    id={id}
                    type="button"
                    disabled={disabled}
                    aria-label={ariaLabel}
                    className="flex h-10 w-full min-w-0 items-center gap-2 rounded-sm border border-transparent bg-muted px-3 text-left text-sm text-foreground transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span
                        className="size-5 shrink-0 rounded-full border border-foreground/10"
                        style={{ backgroundColor: value }}
                        aria-hidden="true"
                    />
                    <span className="truncate font-mono uppercase">
                        {value}
                    </span>
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-auto gap-3">
                <HexColorPicker color={value} onChange={onChange} />
                <HexColorInput
                    color={value}
                    onChange={onChange}
                    prefixed
                    aria-label={
                        ariaLabel ? `${ariaLabel} (hexadecimal)` : undefined
                    }
                    className={cn(
                        'flex h-9 w-full min-w-0 rounded-sm border border-transparent bg-muted px-3 font-mono text-sm text-foreground uppercase transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30',
                    )}
                />
            </PopoverContent>
        </Popover>
    );
}
