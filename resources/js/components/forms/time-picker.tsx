import { Input } from '@/components/ui/input';
import { applyMask, maskMaxLength } from '@/lib/masks';

type TimePickerProps = {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

export function TimePicker({
    id,
    value,
    onChange,
    placeholder = '--:--',
    disabled,
    ...accessibility
}: TimePickerProps) {
    return (
        <Input
            id={id}
            value={value}
            placeholder={placeholder}
            disabled={disabled}
            inputMode="numeric"
            maxLength={maskMaxLength('time')}
            onChange={(event) =>
                onChange(applyMask(event.target.value, 'time'))
            }
            {...accessibility}
        />
    );
}
