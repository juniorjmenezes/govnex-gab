import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Controller, useWatch } from 'react-hook-form';
import type {
    Control,
    FieldErrors,
    FieldPath,
    PathValue,
    UseFormRegister,
    UseFormSetValue,
} from 'react-hook-form';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MaskedInput } from '@/components/ui/masked-input';
import type { MaskType } from '@/lib/masks';

export const STATE_OPTIONS = [
    ['AC', 'Acre'],
    ['AL', 'Alagoas'],
    ['AP', 'Amapá'],
    ['AM', 'Amazonas'],
    ['BA', 'Bahia'],
    ['CE', 'Ceará'],
    ['DF', 'Distrito Federal'],
    ['ES', 'Espírito Santo'],
    ['GO', 'Goiás'],
    ['MA', 'Maranhão'],
    ['MT', 'Mato Grosso'],
    ['MS', 'Mato Grosso do Sul'],
    ['MG', 'Minas Gerais'],
    ['PA', 'Pará'],
    ['PB', 'Paraíba'],
    ['PR', 'Paraná'],
    ['PE', 'Pernambuco'],
    ['PI', 'Piauí'],
    ['RJ', 'Rio de Janeiro'],
    ['RN', 'Rio Grande do Norte'],
    ['RS', 'Rio Grande do Sul'],
    ['RO', 'Rondônia'],
    ['RR', 'Roraima'],
    ['SC', 'Santa Catarina'],
    ['SP', 'São Paulo'],
    ['SE', 'Sergipe'],
    ['TO', 'Tocantins'],
].map(([value, label]) => ({ value, label }));

type Municipality = { id: number; nome: string };

type AddressValues = {
    estado: string;
    municipio: string;
    endereco: string;
    numero: string;
    complemento: string;
    cep: string;
};

type AddressFieldsProps<T extends AddressValues> = {
    control: Control<T>;
    register: UseFormRegister<T>;
    setValue: UseFormSetValue<T>;
    errors: FieldErrors<T>;
    disabled?: boolean;
    locationDisabled?: boolean;
    locationOnly?: boolean;
    bairroName?: FieldPath<T>;
    renderBairro?: (field: FieldPath<T>, error?: string) => ReactNode;
};

export function AddressFields<T extends AddressValues>({
    control,
    register,
    setValue,
    errors,
    disabled = false,
    locationDisabled = disabled,
    locationOnly = false,
    bairroName = 'bairro' as FieldPath<T>,
    renderBairro,
}: AddressFieldsProps<T>) {
    const state = useWatch({
        control,
        name: 'estado' as FieldPath<T>,
    }) as string;
    const selectedMunicipality = useWatch({
        control,
        name: 'municipio' as FieldPath<T>,
    }) as string;
    const lastState = useRef(state);
    const [municipalities, setMunicipalities] = useState<Municipality[]>([]);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const options = useMemo(() => {
        if (!state) {
            return [];
        }

        const items = municipalities.map((item) => ({
            value: item.nome,
            label: item.nome,
        }));

        if (
            selectedMunicipality &&
            !items.some((item) => item.value === selectedMunicipality)
        ) {
            items.unshift({
                value: selectedMunicipality,
                label: `${selectedMunicipality} (atual)`,
            });
        }

        return items;
    }, [municipalities, selectedMunicipality, state]);

    useEffect(() => {
        if (!state) {
            return;
        }

        const controller = new AbortController();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setLoading(true);
        setLoadError(null);
        fetch(
            `https://servicodados.ibge.gov.br/api/v1/localidades/estados/${encodeURIComponent(state)}/municipios`,
            { signal: controller.signal },
        )
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<Municipality[]>)
                    : Promise.reject(new Error('IBGE')),
            )
            .then(setMunicipalities)
            .catch(() => {
                if (!controller.signal.aborted) {
                    setMunicipalities([]);
                    setLoadError('Não foi possível carregar os municípios.');
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [state]);

    useEffect(() => {
        if (
            !locationDisabled &&
            lastState.current !== state &&
            lastState.current !== undefined
        ) {
            setValue(
                'municipio' as FieldPath<T>,
                '' as PathValue<T, FieldPath<T>>,
                {
                    shouldDirty: true,
                    shouldValidate: true,
                },
            );
        }

        lastState.current = state;
    }, [locationDisabled, setValue, state]);

    const error = (name: FieldPath<T>) =>
        errors[name]?.message as string | undefined;
    const bairroError = error(bairroName);

    return (
        <div className="grid gap-5">
            <div
                className={`grid gap-5 ${locationOnly ? 'sm:grid-cols-2' : 'sm:grid-cols-3'}`}
            >
                {!locationOnly && (
                    <div className="space-y-1">
                        <Label>CEP</Label>
                        <MaskedInput
                            mask={'cep' as MaskType}
                            disabled={disabled}
                            {...register('cep' as FieldPath<T>)}
                        />
                        <FieldError message={error('cep' as FieldPath<T>)} />
                    </div>
                )}
                <Controller
                    control={control}
                    name={'estado' as FieldPath<T>}
                    render={({ field }) => (
                        <div className="space-y-1">
                            <Label>UF</Label>
                            <AppSelect
                                options={STATE_OPTIONS}
                                value={field.value as string}
                                onValueChange={field.onChange}
                                disabled={locationDisabled}
                                placeholder="Selecione a UF"
                            />
                            <FieldError
                                message={error('estado' as FieldPath<T>)}
                            />
                        </div>
                    )}
                />
                <Controller
                    control={control}
                    name={'municipio' as FieldPath<T>}
                    render={({ field }) => (
                        <div className="space-y-1">
                            <Label>Município</Label>
                            <AppSelect
                                options={options}
                                value={field.value as string}
                                onValueChange={field.onChange}
                                disabled={locationDisabled || !state || loading}
                                placeholder={
                                    loading
                                        ? 'Carregando municípios...'
                                        : 'Selecione o município'
                                }
                            />
                            <FieldError
                                message={
                                    error('municipio' as FieldPath<T>) ??
                                    loadError ??
                                    undefined
                                }
                            />
                        </div>
                    )}
                />
            </div>
            {!locationOnly && (
                <div className="grid gap-5 md:grid-cols-2">
                    <div className="space-y-1">
                        <Label>Endereço</Label>
                        <Input
                            disabled={disabled}
                            {...register('endereco' as FieldPath<T>)}
                        />
                        <FieldError
                            message={error('endereco' as FieldPath<T>)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label>Número</Label>
                        <Input
                            disabled={disabled}
                            {...register('numero' as FieldPath<T>)}
                        />
                        <FieldError message={error('numero' as FieldPath<T>)} />
                    </div>
                    <div className="space-y-1">
                        <Label>Complemento</Label>
                        <Input
                            disabled={disabled}
                            {...register('complemento' as FieldPath<T>)}
                        />
                        <FieldError
                            message={error('complemento' as FieldPath<T>)}
                        />
                    </div>
                    {renderBairro ? (
                        renderBairro(bairroName, bairroError)
                    ) : (
                        <div className="space-y-1">
                            <Label>Bairro</Label>
                            <Input
                                disabled={disabled}
                                {...register(bairroName)}
                            />
                            <FieldError message={bairroError} />
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export type { AddressValues };
