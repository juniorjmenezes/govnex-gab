import type { InputHTMLAttributes } from 'react';

import { Input } from '../components/input';
import { Label } from '../components/label';
import { Switch } from '../components/switch';
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
 */
export function AuthField({ id, label, error, ...inputProps }: AuthFieldProps) {
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} aria-invalid={Boolean(error)} {...inputProps} />
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
