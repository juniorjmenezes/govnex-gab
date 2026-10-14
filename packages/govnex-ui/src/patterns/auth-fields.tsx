import type { InputHTMLAttributes } from 'react';
import { useState } from 'react';

import { EyeClosedIcon, EyeIcon } from '../icons';
import { Button } from '../components/button';
import { Input } from '../components/input';
import { Label } from '../components/label';
import { Switch } from '../components/switch';
import { cn } from '../lib/utils';
import { FieldError } from './field-error';

export interface AuthFieldProps
    extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
    id: string;
    label: string;
    error?: string;
}

/**
 * Campo rotulado para formulários de autenticação (e-mail, senha): label +
 * input + erro, no mesmo arranjo em todos os produtos Govnex. Funciona
 * controlado (`value`/`onChange`) ou não controlado (`name`, submissão
 * nativa) — só repassa `...inputProps` para o `Input`, então cada app usa o
 * estilo de formulário que já tem (Inertia `<Form>`, `useForm`, etc.).
 *
 * `type="password"` ganha automaticamente o botão de mostrar/ocultar senha
 * (alterna só a exibição local, nunca envia o campo como texto).
 */
export function AuthField({
    id,
    label,
    error,
    type,
    className,
    ...inputProps
}: AuthFieldProps) {
    const [showPassword, setShowPassword] = useState(false);
    const isPassword = type === 'password';

    return (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <div className={isPassword ? 'relative' : undefined}>
                <Input
                    id={id}
                    type={isPassword && showPassword ? 'text' : type}
                    aria-invalid={Boolean(error)}
                    className={cn(isPassword && 'pr-10', className)}
                    {...inputProps}
                />
                {isPassword && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        onClick={() => setShowPassword((prev) => !prev)}
                        className="absolute top-1/2 right-1 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        aria-label={
                            showPassword ? 'Ocultar senha' : 'Mostrar senha'
                        }
                        aria-pressed={showPassword}
                    >
                        {showPassword ? (
                            <EyeClosedIcon className="size-4" />
                        ) : (
                            <EyeIcon className="size-4" />
                        )}
                    </Button>
                )}
            </div>
            <FieldError message={error} />
        </div>
    );
}

export interface AuthRememberToggleProps {
    id?: string;
    name?: string;
    label?: string;
    checked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
    tabIndex?: number;
}

/**
 * Alternância "ficar conectado" dos formulários de login: switch pequeno +
 * rótulo em texto secundário, no mesmo peso visual em todos os produtos.
 */
export function AuthRememberToggle({
    id = 'remember',
    name = 'remember',
    label = 'Ficar conectado',
    checked,
    onCheckedChange,
    tabIndex,
}: AuthRememberToggleProps) {
    return (
        <label className="flex shrink-0 items-center gap-2 text-xs font-medium text-muted-foreground">
            <Switch
                id={id}
                name={name}
                size="sm"
                tabIndex={tabIndex}
                checked={checked}
                onCheckedChange={onCheckedChange}
            />
            {label}
        </label>
    );
}
