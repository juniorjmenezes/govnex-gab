import { Head, Link } from '@inertiajs/react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    MagnifierIcon,
    MaximizeIcon,
    MinimizeIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { MapPanelTitle } from '@/components/voters/map-panel-title';
import { useIsHydrated } from '@/hooks/use-is-hydrated';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { VoterMapMarker, VoterMapSummary } from '@/types';

const ProspectingMapCanvas = lazy(
    () => import('@/components/voters/prospecting-map-canvas'),
);

const normalize = (value: string) =>
    value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase('pt-BR');

export default function ProspectingMap({
    markers,
    summary,
    officeState,
}: {
    markers: VoterMapMarker[];
    summary: VoterMapSummary;
    officeState: string | null;
}) {
    const tenantUrl = useTenantUrl();
    const [query, setQuery] = useState('');
    const [neighborhoodId, setNeighborhoodId] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const mapShellRef = useRef<HTMLDivElement>(null);
    const isHydrated = useIsHydrated();
    const neighborhoods = useMemo(
        () =>
            Array.from(
                new Map(
                    markers
                        .filter(
                            (marker) =>
                                marker.neighborhoodId !== null &&
                                marker.neighborhood !== null,
                        )
                        .map((marker) => [
                            marker.neighborhoodId as number,
                            marker.neighborhood as string,
                        ]),
                ),
            )
                .map(([id, name]) => ({ id, name }))
                .sort((first, second) =>
                    first.name.localeCompare(second.name, 'pt-BR'),
                ),
        [markers],
    );
    const visibleMarkers = useMemo(() => {
        const normalizedQuery = normalize(query.trim());

        return markers.filter((marker) => {
            const belongsToNeighborhood =
                neighborhoodId === '' ||
                marker.neighborhoodId?.toString() === neighborhoodId;
            const matchesQuery =
                normalizedQuery === '' ||
                normalize(
                    [
                        marker.name,
                        marker.address,
                        marker.neighborhood ?? '',
                    ].join(' '),
                ).includes(normalizedQuery);

            return belongsToNeighborhood && matchesQuery;
        });
    }, [markers, neighborhoodId, query]);
    const visibleSelectedId =
        selectedId !== null &&
        visibleMarkers.some((marker) => marker.id === selectedId)
            ? selectedId
            : null;

    useEffect(() => {
        const updateFullscreenState = () =>
            setIsFullscreen(document.fullscreenElement === mapShellRef.current);

        document.addEventListener('fullscreenchange', updateFullscreenState);

        return () =>
            document.removeEventListener(
                'fullscreenchange',
                updateFullscreenState,
            );
    }, []);

    const toggleFullscreen = useCallback(async () => {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await mapShellRef.current?.requestFullscreen();
        }
    }, []);

    return (
        <>
            <Head title="Mapa de prospecção" />
            <div
                ref={mapShellRef}
                className="relative isolate z-0 h-[calc(100svh-var(--app-shell-height,3.5rem))] overflow-hidden bg-muted"
            >
                {isHydrated ? (
                    <Suspense
                        fallback={
                            <div className="grid h-full place-items-center text-sm text-muted-foreground">
                                Carregando mapa de prospecção...
                            </div>
                        }
                    >
                        <ProspectingMapCanvas
                            markers={visibleMarkers}
                            selectedId={visibleSelectedId}
                            onSelect={setSelectedId}
                            state={officeState}
                        />
                    </Suspense>
                ) : (
                    <div className="grid h-full place-items-center text-sm text-muted-foreground">
                        Carregando mapa de prospecção...
                    </div>
                )}

                <Card className="absolute top-4 left-4 z-[500] max-h-[calc(100%-2rem)] w-[calc(100%-5.5rem)] max-w-sm gap-4 overflow-y-auto bg-card/95 p-4 backdrop-blur">
                    <MapPanelTitle
                        title="Mapa de prospecção"
                        description="Distribuição residencial dos eleitores cadastrados."
                    />

                    <div className="grid grid-cols-3 gap-2 text-center">
                        <Metric value={summary.totalVoters} label="Eleitores" />
                        <Metric value={summary.locatedVoters} label="No mapa" />
                        <Metric
                            value={summary.withoutLocation}
                            label="Sem local"
                        />
                    </div>

                    <div className="space-y-2">
                        <div className="relative">
                            <MagnifierIcon className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                className="pl-9"
                                placeholder="Nome, rua ou bairro"
                                aria-label="Buscar eleitor no mapa"
                            />
                        </div>
                        <AppSelect
                            value={neighborhoodId}
                            onValueChange={setNeighborhoodId}
                            emptyLabel="Todos os bairros"
                            options={neighborhoods.map((neighborhood) => ({
                                value: neighborhood.id.toString(),
                                label: neighborhood.name,
                            }))}
                            aria-label="Filtrar eleitores por bairro"
                        />
                    </div>

                    {/* Contagem e refinamento no rodapé da lista, como no
                        mapa de eleitores. A lista aparece mesmo vazia, para a
                        contagem e o limpar filtros não sumirem. */}
                    <div className="divide-y overflow-hidden rounded-md border">
                        {visibleMarkers.slice(0, 8).map((marker) => (
                            <button
                                key={marker.id}
                                type="button"
                                className="flex w-full cursor-pointer items-center gap-2 px-2 py-2.5 text-left transition-colors hover:bg-muted"
                                onClick={() => setSelectedId(marker.id)}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-xs font-medium uppercase">
                                        {marker.name}
                                    </span>
                                    <span className="block truncate text-[11px] text-muted-foreground uppercase">
                                        {[marker.address, marker.neighborhood]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                            </button>
                        ))}
                        <div className="flex items-center justify-between gap-3 px-2 py-2.5 text-xs text-muted-foreground">
                            <p>
                                {visibleMarkers.length}{' '}
                                {visibleMarkers.length === 1
                                    ? 'eleitor visível'
                                    : 'eleitores visíveis'}
                                {visibleMarkers.length > 8 &&
                                    ' · refine a busca para ver outros resultados.'}
                            </p>
                            {(query || neighborhoodId) && (
                                <button
                                    type="button"
                                    className="shrink-0 cursor-pointer font-medium text-primary hover:underline"
                                    onClick={() => {
                                        setQuery('');
                                        setNeighborhoodId('');
                                        setSelectedId(null);
                                    }}
                                >
                                    Limpar filtros
                                </button>
                            )}
                        </div>
                    </div>

                    {summary.truncated && (
                        <Badge variant="outline">
                            Exibindo os primeiros 5.000 eleitores localizados.
                        </Badge>
                    )}

                    <Button variant="outline" size="sm" asChild>
                        <Link href={tenantUrl('/cidadaos')}>
                            <UsersGroupRoundedIcon />
                            Abrir cadastro de cidadãos
                        </Link>
                    </Button>
                </Card>

                <Button
                    type="button"
                    variant="secondary"
                    size="icon"
                    className="absolute top-4 right-4 z-[500] shadow-lg"
                    onClick={toggleFullscreen}
                    aria-label={
                        isFullscreen
                            ? 'Sair da tela cheia'
                            : 'Abrir mapa em tela cheia'
                    }
                >
                    {isFullscreen ? <MinimizeIcon /> : <MaximizeIcon />}
                </Button>
            </div>
        </>
    );
}

function Metric({ value, label }: { value: number; label: string }) {
    return (
        <div className="rounded-lg bg-muted p-2">
            <strong className="block font-mono text-lg font-bold tabular-nums">
                {value}
            </strong>
            <span className="text-[11px] text-muted-foreground">{label}</span>
        </div>
    );
}

ProspectingMap.layout = {
    breadcrumbs: [
        { title: 'Mapa de prospecção', href: '/eleitores/prospeccao' },
    ],
};
