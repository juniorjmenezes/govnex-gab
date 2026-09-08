import type { ReactNode } from 'react';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { InputGroupAddon } from '@/components/ui/input-group';
import { cn } from '@/lib/utils';

export type AppSelectOption = {
    value: string;
    label: string;
    disabled?: boolean;
};

type AppSelectProps = {
    options: AppSelectOption[];
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    placeholder?: string;
    emptyLabel?: string;
    id?: string;
    name?: string;
    disabled?: boolean;
    clearable?: boolean;
    className?: string;
    startAdornment?: ReactNode;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

export function AppSelect({
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
        <Combobox
            items={items}
            value={selectedItem}
            onValueChange={(item) => onValueChange?.(item?.value ?? '')}
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
                className={cn('w-full', className)}
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
    );
}
