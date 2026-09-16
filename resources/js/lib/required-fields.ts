import type { useFormContext } from '@inertiajs/react';

/** Instância exposta pelo `ref` do `<Form>` do Inertia. */
export type InertiaFormRef = NonNullable<
    ReturnType<typeof useFormContext<Record<string, unknown>>>
>;

type ErrorTarget = {
    clearErrors: () => void;
    setError: (errors: Record<string, string>) => void;
};

const isBlank = (value: unknown) =>
    value === undefined ||
    value === null ||
    (typeof value === 'string' && value.trim() === '') ||
    (Array.isArray(value) && value.length === 0);

/**
 * Validação de obrigatórios para formulários do Inertia, no padrão do cadastro
 * de demandas: a mensagem aparece em vermelho abaixo do campo, e não no balão
 * nativo do navegador nem no texto em inglês do servidor. Por isso os campos
 * usam `aria-required` em vez de `required`. Retorna `false` quando falta
 * algo, para o envio ser interrompido.
 */
export function checkRequiredFields<T extends object>(
    data: T,
    form: ErrorTarget,
    messages: Partial<Record<keyof T & string, string>>,
): boolean {
    const errors = Object.fromEntries(
        Object.entries(messages).filter(([field]) =>
            isBlank(data[field as keyof T]),
        ),
    ) as Record<string, string>;

    form.clearErrors();

    if (Object.keys(errors).length === 0) {
        return true;
    }

    form.setError(errors);

    return false;
}

/** Mesma validação para o `<Form>` do Inertia, usada no `onBefore`. */
export function checkRequiredFormFields(
    form: InertiaFormRef | null,
    messages: Record<string, string>,
): boolean {
    return form ? checkRequiredFields(form.getData(), form, messages) : true;
}
