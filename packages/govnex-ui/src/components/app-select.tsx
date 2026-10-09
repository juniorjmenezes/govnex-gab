import type { ReactNode } from 'react';

import { cn } from '../lib/utils';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from './combobox';
import { InputGroupAddon } from './input-group';

export type AppSelectOption = {
    value: string;
    label: string;
    disabled?: boolean;
};

export type AppSelectProps = {
    options: AppSelectOption[];
    /** Valor controlado; '' = nenhum. */
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    placeholder?: string;
    /** Rótulo da opção "nenhum" (devolve ''); sem ele não há opção vazia. */
    emptyLabel?: string;
    id?: string;
    name?: string;
    disabled?: boolean;
    /** Mostra o botão de limpar quando há seleção (padrão: sim). */
    clearable?: boolean;
    className?: string;
    startAdornment?: ReactNode;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

/**
 * Seletor em dropdown com busca: campo de texto que filtra a lista ao digitar, seta para abrir, teclado e leitor de tela
 * (Base UI Combobox). É o seletor padrão dos produtos GOVNEX; o `Select` simples fica para listas curtas sem busca.
 */
function AppSelect({
    options,
    value,
    defaultValue = '',
    onValueChange,
    placeholder = 'Selecione',
    emptyLabel,
    id,
    name,
    disabled,
    clearable = true,
    className,
    startAdornment,
    ...accessibility
}: AppSelectProps) {
    const items = emptyLabel
        ? [{ value: '', label: emptyLabel }, ...options]
        : options;
    const selectedValue = value ?? defaultValue;
    const selectedItem =
        items.find((item) => item.value === selectedValue) ?? null;
    const hasClearableSelection =
        clearable && selectedItem !== null && selectedItem.value !== '';

    return (
        <div data-slot="app-select" className={cn('w-full', className)}>
            <Combobox
                items={items}
                value={selectedItem}
                onValueChange={(item: AppSelectOption | null) =>
                    onValueChange?.(item?.value ?? '')
                }
                itemToStringValue={(item: AppSelectOption) => item.value}
                itemToStringLabel={(item: AppSelectOption) => item.label}
                isItemEqualToValue={(a: AppSelectOption, b: AppSelectOption) =>
                    a.value === b.value
                }
                name={name}
                disabled={disabled}
            >
                <ComboboxInput
                    id={id}
                    placeholder={placeholder}
                    disabled={disabled}
                    showClear={hasClearableSelection}
                    className="w-full"
                    {...accessibility}
                >
                    {startAdornment && (
                        <InputGroupAddon align="inline-start">
                            {startAdornment}
                        </InputGroupAddon>
                    )}
                </ComboboxInput>
                <ComboboxContent>
                    <ComboboxEmpty>Nenhum resultado encontrado.</ComboboxEmpty>
                    <ComboboxList>
                        {(item: AppSelectOption) => (
                            <ComboboxItem
                                key={item.value}
                                value={item}
                                disabled={item.disabled}
                            >
                                {item.label}
                            </ComboboxItem>
                        )}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
        </div>
    );
}

export { AppSelect };
