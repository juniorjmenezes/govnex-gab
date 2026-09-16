import { DatePicker } from '@/components/forms/date-picker';
import { AppSelect } from '@/components/ui/app-select';
import { Label } from '@/components/ui/label';

/**
 * Campos das gavetas de filtro das listagens. Estavam copiados em
 * demandas, eventos e relatórios — três definições locais do mesmo par
 * rótulo + controle, que iam divergindo a cada ajuste de layout.
 */
export function FilterSelect({
    label,
    value,
    options,
    onChange,
    clearable = true,
}: {
    label: string;
    value: string;
    options: Array<{ value: string; label: string }>;
    onChange: (value: string) => void;
    clearable?: boolean;
}) {
    return (
        <Label className="grid gap-1">
            <span>{label}</span>
            <AppSelect
                value={value}
                onValueChange={onChange}
                options={options}
                emptyLabel={clearable ? 'Todos' : undefined}
                clearable={clearable}
            />
        </Label>
    );
}

export function DateFilter({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <Label className="grid gap-1">
            <span>{label}</span>
            <DatePicker value={value} onChange={onChange} />
        </Label>
    );
}
