import type { ComponentProps } from 'react';
import { FieldError } from '@/components/forms/field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export function DateTimeFieldPair({
    dateLabel,
    timeLabel,
    dateInputProps,
    timeInputProps,
    dateError,
    timeError,
    required = false,
    className,
}: {
    dateLabel: string;
    timeLabel: string;
    dateInputProps: Omit<ComponentProps<typeof Input>, 'type'>;
    timeInputProps: Omit<ComponentProps<typeof Input>, 'type'>;
    dateError?: string;
    timeError?: string;
    required?: boolean;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-4 sm:grid-cols-2', className)}>
            <div className="space-y-1">
                <Label htmlFor={dateInputProps.id}>
                    {dateLabel} {required && <span aria-hidden="true">*</span>}
                </Label>
                <Input
                    type="date"
                    {...dateInputProps}
                    aria-invalid={Boolean(dateError)}
                />
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
