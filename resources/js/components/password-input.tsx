import { EyeClosedIcon, EyeIcon } from '@solar-icons/react/outline';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn('pr-10', className)}
                ref={ref}
                {...props}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                onClick={() => setShowPassword((prev) => !prev)}
                className="absolute top-1/2 right-1 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                aria-pressed={showPassword}
            >
                {showPassword ? (
                    <EyeClosedIcon className="size-4" />
                ) : (
                    <EyeIcon className="size-4" />
                )}
            </Button>
        </div>
    );
}
