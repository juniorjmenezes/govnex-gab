import { useEffect, useRef, useState } from 'react';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { useTenantUrl } from '@/hooks/use-tenant-url';

export type CitizenSelectOption = {
    id: number;
    nome: string;
    bairro_id?: number | null;
    endereco?: string | null;
    numero?: string | null;
    complemento?: string | null;
    ponto_referencia?: string | null;
};

type Props = {
    id?: string;
    value: string;
    /** Opções iniciais (ex.: o solicitante atual da demanda em edição). */
    initialOptions?: CitizenSelectOption[];
    onChange: (citizenId: string, citizen: CitizenSelectOption | null) => void;
    placeholder?: string;
    'aria-invalid'?: boolean;
};

/** Seletor de cidadão com busca no servidor (a lista completa não é carregada). */
export function CitizenSelect({
    id,
    value,
    initialOptions = [],
    onChange,
    placeholder = 'Selecione o cidadão',
    'aria-invalid': invalid,
}: Props) {
    const tenantUrl = useTenantUrl();
    const [results, setResults] =
        useState<CitizenSelectOption[]>(initialOptions);
    const [selected, setSelected] = useState<CitizenSelectOption | null>(
        initialOptions.find((item) => item.id.toString() === value) ?? null,
    );
    const controller = useRef<AbortController | null>(null);

    useEffect(() => () => controller.current?.abort(), []);

    const search = (term: string) => {
        controller.current?.abort();
        const current = new AbortController();
        controller.current = current;

        fetch(
            `${tenantUrl('/demandas/cidadaos/buscar')}?q=${encodeURIComponent(term)}`,
            {
                signal: current.signal,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        )
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<CitizenSelectOption[]>)
                    : Promise.reject(new Error('search')),
            )
            .then(setResults)
            .catch(() => undefined);
    };

    // A seleção atual precisa continuar nos itens mesmo fora do resultado da busca.
    const items =
        selected && !results.some((item) => item.id === selected.id)
            ? [selected, ...results]
            : results;

    return (
        <Combobox<CitizenSelectOption>
            items={items}
            value={selected}
            filter={null}
            onValueChange={(item) => {
                setSelected(item);
                onChange(item ? item.id.toString() : '', item);
            }}
            onOpenChange={(open) => {
                if (open) {
                    search('');
                }
            }}
            onInputValueChange={(term, details) => {
                if (details.reason === 'input-change') {
                    search(term);
                }
            }}
            itemToStringValue={(item) => item.id.toString()}
            itemToStringLabel={(item) => item.nome}
            isItemEqualToValue={(a, b) => a.id === b.id}
        >
            <ComboboxInput
                id={id}
                placeholder={placeholder}
                showClear={false}
                aria-invalid={invalid}
                className="w-full"
            />
            <ComboboxContent>
                <ComboboxEmpty>Nenhum cidadão encontrado.</ComboboxEmpty>
                <ComboboxList>
                    {(item: CitizenSelectOption) => (
                        <ComboboxItem key={item.id} value={item}>
                            {item.nome}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
