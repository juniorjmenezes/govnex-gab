import type { ChangeEvent, ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { applyMask, maskMaxLength, type MaskType } from '@/lib/masks';

type MaskedInputProps = Omit<ComponentProps<typeof Input>, 'type'> & {
    mask: MaskType;
};

function MaskedInput({
    mask,
    onChange,
    maxLength,
    inputMode = 'numeric',
    ...props
}: MaskedInputProps) {
    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        event.target.value = applyMask(event.target.value, mask);
        onChange?.(event);
    };

    return (
        <Input
            {...props}
            type="text"
            inputMode={inputMode}
            autoComplete="off"
            maxLength={maxLength ?? maskMaxLength(mask)}
            onChange={handleChange}
        />
    );
}

export { MaskedInput };
