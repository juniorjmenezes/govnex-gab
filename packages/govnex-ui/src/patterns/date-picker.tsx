import { format, isValid, parse } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useEffect, useRef, useState } from 'react';

import { CalendarIcon } from '../icons';
import { Calendar } from '../components/calendar';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '../components/input-group';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '../components/popover';
import { applyMask, maskMaxLength } from '../lib/masks';

const ISO_DATE_FORMAT = 'yyyy-MM-dd';
const DISPLAY_DATE_FORMAT = 'dd/MM/yyyy';

function parseIsoDate(value: string): Date | undefined {
    if (!value) {
        return undefined;
    }

    const date = parse(value, ISO_DATE_FORMAT, new Date());

    return isValid(date) ? date : undefined;
}

function parseDisplayDate(value: string): Date | undefined {
    const date = parse(value, DISPLAY_DATE_FORMAT, new Date());

    return isValid(date) ? date : undefined;
}

function formatDisplayDate(date: Date | undefined): string {
    return date ? format(date, DISPLAY_DATE_FORMAT, { locale: ptBR }) : '';
}

type DatePickerProps = {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    /** Data mínima (ISO). Desabilita os dias anteriores no calendário. */
    min?: string;
    /** Data máxima (ISO). Desabilita os dias posteriores no calendário. */
    max?: string;
    /**
     * Mostra listas de mês e ano no calendário, para datas distantes como a
     * de nascimento. O intervalo vai de `startYear` até a data máxima.
     */
    yearNavigation?: boolean;
    startYear?: number;
    className?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
    'aria-label'?: string;
};

export function DatePicker({
    id,
    value,
    onChange,
    placeholder = 'dd/mm/aaaa',
    disabled,
    min,
    max,
    yearNavigation = false,
    startYear = 1900,
    className,
    ...accessibility
}: DatePickerProps) {
    const [open, setOpen] = useState(false);
    const selectedDate = parseIsoDate(value);
    const minDate = min ? parseIsoDate(min) : undefined;
    const maxDate = max ? parseIsoDate(max) : undefined;
    const disabledDays = [
        ...(minDate ? [{ before: minDate }] : []),
        ...(maxDate ? [{ after: maxDate }] : []),
    ];
    const [month, setMonth] = useState<Date | undefined>(selectedDate);
    const [inputValue, setInputValue] = useState(
        formatDisplayDate(selectedDate),
    );
    const lastEmitted = useRef(value);

    // Ressincroniza o texto digitado quando o valor muda por fora (edição
    // carregada do servidor, reset do formulário) — não quando a mudança
    // veio do próprio usuário digitando, pra não reformatar a cada tecla.
    useEffect(() => {
        if (value === lastEmitted.current) {
            return;
        }

        lastEmitted.current = value;
        const nextDate = parseIsoDate(value);
        setInputValue(formatDisplayDate(nextDate));
        setMonth(nextDate);
    }, [value]);

    const emit = (nextValue: string) => {
        lastEmitted.current = nextValue;
        onChange(nextValue);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverAnchor asChild>
                <InputGroup className={className}>
                    <InputGroupInput
                        id={id}
                        value={inputValue}
                        placeholder={placeholder}
                        disabled={disabled}
                        inputMode="numeric"
                        maxLength={maskMaxLength('date')}
                        onChange={(event) => {
                            const typed = applyMask(event.target.value, 'date');
                            setInputValue(typed);

                            const parsedDate = parseDisplayDate(typed);

                            if (parsedDate) {
                                setMonth(parsedDate);
                                emit(format(parsedDate, ISO_DATE_FORMAT));
                            } else if (typed === '') {
                                emit('');
                            }
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                setOpen(true);
                            }
                        }}
                        {...accessibility}
                    />
                    <InputGroupAddon align="inline-end">
                        <PopoverTrigger asChild>
                            <InputGroupButton
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                disabled={disabled}
                                aria-label="Selecionar data"
                            >
                                <CalendarIcon />
                            </InputGroupButton>
                        </PopoverTrigger>
                    </InputGroupAddon>
                </InputGroup>
            </PopoverAnchor>
            <PopoverContent
                className="w-auto overflow-hidden p-0"
                align="end"
                sideOffset={6}
            >
                <Calendar
                    mode="single"
                    locale={ptBR}
                    disabled={disabledDays.length ? disabledDays : undefined}
                    captionLayout={yearNavigation ? 'dropdown' : 'label'}
                    startMonth={
                        yearNavigation
                            ? (minDate ?? new Date(startYear, 0))
                            : undefined
                    }
                    endMonth={
                        yearNavigation ? (maxDate ?? new Date()) : undefined
                    }
                    selected={selectedDate}
                    month={month}
                    onMonthChange={setMonth}
                    onSelect={(date) => {
                        setInputValue(formatDisplayDate(date));
                        emit(date ? format(date, ISO_DATE_FORMAT) : '');
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
