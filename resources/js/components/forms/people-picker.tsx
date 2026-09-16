import { useId, useMemo, useState } from 'react';
import { FieldError } from '@/components/forms/field-error';
import { AddIcon, CloseIcon, MagnifierIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type PeopleOption = { id: number; label: string };

/**
 * Seleção de pessoas no mesmo formato de "Cidadãos convidados": quem já foi
 * escolhido vira chip removível e o restante aparece numa lista filtrável.
 * Diferente do seletor de cidadãos, a lista já vem carregada — por isso a
 * busca é local e a lista aparece de imediato.
 */
export function PeoplePicker({
    label,
    options,
    value,
    onChange,
    error,
    searchPlaceholder = 'Buscar pelo nome',
    emptyLabel = 'Nenhum registro disponível.',
    noResultsLabel = 'Nenhum resultado encontrado.',
    noSelectionLabel = 'Ninguém selecionado.',
}: {
    label: string;
    options: PeopleOption[];
    value: number[];
    onChange: (value: number[]) => void;
    error?: string;
    searchPlaceholder?: string;
    emptyLabel?: string;
    noResultsLabel?: string;
    noSelectionLabel?: string;
}) {
    const searchId = useId();
    const [query, setQuery] = useState('');

    const selected = useMemo(
        () => options.filter((option) => value.includes(option.id)),
        [options, value],
    );

    const available = useMemo(() => {
        const term = query.trim().toLocaleLowerCase('pt-BR');

        return options.filter(
            (option) =>
                !value.includes(option.id) &&
                (term === '' ||
                    option.label.toLocaleLowerCase('pt-BR').includes(term)),
        );
    }, [options, value, query]);

    return (
        <div className="space-y-1">
            <Label htmlFor={searchId}>{label}</Label>
            <div className="space-y-3">
                {selected.length > 0 && (
                    <div
                        className="flex flex-wrap gap-2"
                        aria-label={`${label} selecionados`}
                    >
                        {selected.map((option) => (
                            <span
                                key={option.id}
                                className="inline-flex items-center gap-1 rounded-md border bg-muted/50 py-1 pr-1 pl-2 text-sm"
                            >
                                {option.label}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    className="size-5"
                                    onClick={() =>
                                        onChange(
                                            value.filter(
                                                (id) => id !== option.id,
                                            ),
                                        )
                                    }
                                    aria-label={`Remover ${option.label}`}
                                >
                                    <CloseIcon />
                                </Button>
                            </span>
                        ))}
                    </div>
                )}

                {options.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {emptyLabel}
                    </p>
                ) : (
                    <>
                        <div className="relative">
                            <MagnifierIcon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id={searchId}
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                className="pl-9"
                                placeholder={searchPlaceholder}
                                autoComplete="off"
                                aria-invalid={Boolean(error)}
                            />
                        </div>

                        <div
                            className="max-h-64 overflow-y-auto rounded-md border"
                            role="listbox"
                            aria-label={`Resultados para ${label.toLocaleLowerCase('pt-BR')}`}
                        >
                            {available.length === 0 ? (
                                <p className="p-3 text-sm text-muted-foreground">
                                    {noResultsLabel}
                                </p>
                            ) : (
                                available.map((option) => (
                                    <button
                                        key={option.id}
                                        type="button"
                                        role="option"
                                        aria-selected="false"
                                        className="flex w-full items-center justify-between gap-3 border-b px-3 py-2 text-left text-sm transition-colors last:border-b-0 hover:bg-accent"
                                        onClick={() => {
                                            onChange([...value, option.id]);
                                            setQuery('');
                                        }}
                                    >
                                        <span className="truncate">
                                            {option.label}
                                        </span>
                                        <AddIcon className="size-4 shrink-0 text-muted-foreground" />
                                    </button>
                                ))
                            )}
                        </div>
                    </>
                )}

                {selected.length === 0 && options.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        {noSelectionLabel}
                    </p>
                )}
            </div>
            <FieldError message={error} />
        </div>
    );
}
