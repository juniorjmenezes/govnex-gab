import {
    AltArrowDownIcon,
    CloseIcon,
    MagnifierIcon,
    MapPointIcon,
} from '@solar-icons/react/outline';
import { LoaderCircle } from 'lucide-react';
import { lazy, Suspense, useCallback, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Surface } from '@/components/ui/surface';
import { useIsHydrated } from '@/hooks/use-is-hydrated';
import { cn } from '@/lib/utils';

type Coordinates = {
    latitude: number;
    longitude: number;
};

type AddressParts = {
    street: string;
    number: string;
    neighborhood: string;
    city: string;
    state: string;
};

type LocationSource = 'endereco' | 'logradouro' | 'municipio' | 'manual';

type GeocodingResult = Coordinates & {
    label: string;
    precision: 'address' | 'street' | 'municipality';
};

const CitizenLocationMap = lazy(
    () => import('@/components/citizens/citizen-location-map'),
);

export function CitizenLocationPicker({
    address,
    coordinates,
    source,
    onChange,
}: {
    address: AddressParts;
    coordinates: Coordinates | null;
    source: LocationSource | null;
    onChange: (
        coordinates: Coordinates | null,
        source: LocationSource | null,
    ) => void;
}) {
    const [open, setOpen] = useState(true);
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<GeocodingResult[]>([]);
    const [error, setError] = useState<string | null>(null);
    const isHydrated = useIsHydrated();
    const canSearch = Boolean(
        address.street.trim() && address.city.trim() && address.state.trim(),
    );

    const selectResult = useCallback(
        (result: GeocodingResult) => {
            const resultSource: Exclude<LocationSource, 'manual'> =
                result.precision === 'address'
                    ? 'endereco'
                    : result.precision === 'street'
                      ? 'logradouro'
                      : 'municipio';

            onChange(
                {
                    latitude: result.latitude,
                    longitude: result.longitude,
                },
                resultSource,
            );
            setResults([]);
            setError(null);
        },
        [onChange],
    );

    const searchAddress = async () => {
        if (!canSearch || loading) {
            return;
        }

        setLoading(true);
        setError(null);
        setResults([]);

        try {
            const query = new URLSearchParams({
                logradouro: address.street,
                numero: address.number,
                bairro: address.neighborhood,
                municipio: address.city,
                estado: address.state,
            });
            const response = await fetch(
                `/cidadaos/localizacao/buscar?${query.toString()}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const payload = (await response.json()) as {
                message?: string;
                results?: GeocodingResult[];
            };

            if (!response.ok) {
                throw new Error(
                    payload.message ?? 'Não foi possível buscar o endereço.',
                );
            }

            const matches = payload.results ?? [];

            if (matches.length === 0) {
                setError(
                    'Endereço não encontrado. Revise os dados e tente novamente.',
                );
            } else if (matches.length === 1) {
                selectResult(matches[0]);
            } else {
                setResults(matches);
            }
        } catch (caughtError) {
            setError(
                caughtError instanceof Error
                    ? caughtError.message
                    : 'Não foi possível buscar o endereço.',
            );
        } finally {
            setLoading(false);
        }
    };

    const updateManually = useCallback(
        (nextCoordinates: Coordinates) => {
            onChange(nextCoordinates, 'manual');
        },
        [onChange],
    );

    const clear = () => {
        onChange(null, null);
        setResults([]);
        setError(null);
    };

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn(
                        'w-full justify-between',
                        coordinates &&
                            'border-primary text-primary hover:text-primary',
                    )}
                >
                    <span className="flex items-center gap-2">
                        <MapPointIcon data-icon="inline-start" />
                        {coordinates
                            ? 'Localização definida'
                            : 'Definir localização residencial'}
                    </span>
                    <AltArrowDownIcon
                        className={cn(
                            'transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-3">
                <div className="space-y-3 rounded-2xl border bg-muted/20 p-3 sm:p-4">
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="secondary"
                            className="flex-1"
                            disabled={!canSearch || loading}
                            onClick={searchAddress}
                        >
                            {loading ? (
                                <LoaderCircle
                                    className="animate-spin"
                                    data-icon="inline-start"
                                />
                            ) : (
                                <MagnifierIcon data-icon="inline-start" />
                            )}
                            {loading ? 'Buscando...' : 'Buscar endereço'}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={!coordinates && results.length === 0}
                            onClick={clear}
                        >
                            <CloseIcon data-icon="inline-start" />
                            Limpar
                        </Button>
                    </div>

                    {!canSearch && (
                        <p className="text-center text-xs text-muted-foreground">
                            Informe o endereço e selecione o bairro antes de
                            buscar.
                        </p>
                    )}

                    {error && (
                        <p
                            className="text-center text-sm text-destructive"
                            role="alert"
                        >
                            {error}
                        </p>
                    )}

                    {results.length > 0 && (
                        <Surface className="space-y-1 p-1">
                            <p className="px-2 py-1 text-xs font-medium text-muted-foreground">
                                Selecione o endereço correto
                            </p>
                            {results.map((result) => (
                                <button
                                    key={`${result.latitude}-${result.longitude}`}
                                    type="button"
                                    className="w-full rounded-lg px-2 py-2 text-left text-sm transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    onClick={() => selectResult(result)}
                                >
                                    {result.label}
                                </button>
                            ))}
                        </Surface>
                    )}

                    {coordinates && isHydrated && (
                        <Suspense
                            fallback={
                                <div className="grid h-72 place-items-center rounded-2xl border bg-muted text-sm text-muted-foreground">
                                    Carregando mapa...
                                </div>
                            }
                        >
                            <CitizenLocationMap
                                coordinates={coordinates}
                                onChange={updateManually}
                            />
                        </Suspense>
                    )}

                    {coordinates && (
                        <div className="space-y-1 text-center">
                            {source === 'logradouro' && (
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    O número não foi localizado; o marcador foi
                                    posicionado na rua. Ajuste-o no mapa.
                                </p>
                            )}
                            {source === 'municipio' && (
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    A rua não foi localizada; o marcador foi
                                    centralizado no município. Ajuste-o no mapa.
                                </p>
                            )}
                            <p className="text-xs text-muted-foreground">
                                Lat: {coordinates.latitude.toFixed(6)} | Lng:{' '}
                                {coordinates.longitude.toFixed(6)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Clique no mapa ou arraste o marcador para
                                ajustar a localização.
                            </p>
                        </div>
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
