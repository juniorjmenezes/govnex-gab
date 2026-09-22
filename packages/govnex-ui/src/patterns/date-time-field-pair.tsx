import type { ComponentProps } from 'react';

import { cn } from '../lib/utils';
import { Input } from '../components/input';
import { Label } from '../components/label';
import { DatePicker } from './date-picker';
import { FieldError } from './field-error';

export function DateTimeFieldPair({
    dateLabel,
    timeLabel,
    dateProps,
    timeInputProps,
    dateError,
    timeError,
    required = false,
    className,
}: {
    dateLabel: string;
    timeLabel: string;
    dateProps: ComponentProps<typeof DatePicker>;
    timeInputProps: Omit<ComponentProps<typeof Input>, 'type'>;
    dateError?: string;
    timeError?: string;
    required?: boolean;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-4 sm:grid-cols-2', className)}>
            <div className="space-y-1">
                <Label htmlFor={dateProps.id}>
                    {dateLabel} {required && <span aria-hidden="true">*</span>}
                </Label>
                <DatePicker {...dateProps} aria-invalid={Boolean(dateError)} />
                <FieldError message={dateError} />
            </div>
            <div className="space-y-1">
                <Label htmlFor={timeInputProps.id}>
                    {timeLabel} {required && <span aria-hidden="true">*</span>}
                </Label>
                <Input
                    type="time"
                    {...timeInputProps}
                    aria-invalid={Boolean(timeError)}
                />
                <FieldError message={timeError} />
            </div>
        </div>
    );
}
