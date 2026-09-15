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
    AltArrowUpIcon,
    CloseIcon,
    FlameIcon,
    MagnifierIcon,
    MaximizeIcon,
    MinimizeIcon,
    RecordIcon,
    SettingsIcon,
} from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type {
    MapFocusRequest,
    MapViewMode,
} from '@/components/voters/electoral-heatmap-canvas';
import { MapPanelTitle } from '@/components/voters/map-panel-title';
import { useIsHydrated } from '@/hooks/use-is-hydrated';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type { ElectoralMapPoint, ElectoralMapSummary } from '@/types';

const ElectoralHeatmapCanvas = lazy(
    () => import('@/components/voters/electoral-heatmap-canvas'),
);

// Quantos locais aparecem no ranking antes de pedir para refinar a busca.
const RANKING_LIMIT = 3;

const normalize = (value: string) =>
    value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase('pt-BR');

const formatCoverage = (value: number) =>
    `${value.toLocaleString('pt-BR', {
        minimumFractionDigits: Number.isInteger(value) ? 0 : 1,
        maximumFractionDigits: 1,
    })}%`;

export default function ElectoralMap({
    points,
    summary,
    officeState,
}: {
    points: ElectoralMapPoint[];
    summary: ElectoralMapSummary;
    officeState: string | null;
}) {
    const tenantUrl = useTenantUrl();
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [hoveredId, setHoveredId] = useState<number | null>(null);
    const [focusRequest, setFocusRequest] = useState<MapFocusRequest>(null);
    const [viewMode, setViewMode] = useState<MapViewMode>('heat');
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const mapShellRef = useRef<HTMLDivElement>(null);
    const rankingItemRefs = useRef(new Map<number, HTMLButtonElement>());
    const isHydrated = useIsHydrated();

    const visiblePoints = useMemo(() => {
        const normalizedQuery = normalize(query.trim());

        if (normalizedQuery === '') {
            return points;
        }

        return points.filter((point) =>
            normalize(
                [
                    point.name,
                    point.address ?? '',
                    point.neighborhood ?? '',
                ].join(' '),
            ).includes(normalizedQuery),
        );
    }, [points, query]);
    const rankedPoints = useMemo(
        () =>
            [...visiblePoints].sort(
                (first, second) => second.votes - first.votes,
            ),
        [visiblePoints],
    );
    // Garante que o local selecionado (mesmo vindo de um clique direto no
    // mapa) sempre apareça no ranking visível, mesmo fora do top N.
    const visibleRankedPoints = useMemo(() => {
        const top = rankedPoints.slice(0, RANKING_LIMIT);

        if (
            selectedId !== null &&
            !top.some((point) => point.id === selectedId)
        ) {
            const selectedPoint = rankedPoints.find(
                (point) => point.id === selectedId,
            );

            if (selectedPoint) {
                return [...top.slice(0, RANKING_LIMIT - 1), selectedPoint];
            }
        }

        return top;
    }, [rankedPoints, selectedId]);

    // Máximo calculado a partir do conjunto completo (não do filtrado por
    // busca), para que a escala de cor/tamanho do mapa não mude conforme o
    // usuário pesquisa um local específico.
    const maxVotes = useMemo(
        () =>
            points.length > 0
                ? Math.max(...points.map((point) => point.votes))
                : 0,
        [points],
    );

    const visibleSelectedId =
        selectedId !== null &&
        visiblePoints.some((point) => point.id === selectedId)
            ? selectedId
            : null;
    const coverage =
        summary.totalLocations > 0
            ? (summary.locatedLocations / summary.totalLocations) * 100
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

    // Sempre que a seleção muda (clique no mapa ou no ranking), garante que
    // o item correspondente do ranking fique visível na lista lateral.
    useEffect(() => {
        if (selectedId === null) {
            return;
        }

        rankingItemRefs.current
            .get(selectedId)
            ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }, [selectedId]);

    const toggleFullscreen = useCallback(async () => {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await mapShellRef.current?.requestFullscreen();
        }
    }, []);

    // Clique direto num ponto do mapa: só destaca (o próprio Leaflet já
    // abre o popup do marcador clicado).
    const handleMapSelect = useCallback((id: number) => {
        setSelectedId(id);
    }, []);

    // Clique num item do ranking: além de destacar, pede ao mapa para
    // centralizar/dar zoom e abrir o popup daquele local.
    const handleRankingSelect = useCallback((id: number) => {
        setSelectedId(id);
        setFocusRequest({ id, nonce: Date.now() });
        setSheetOpen(false);
    }, []);

    const handleRankingHoverStart = useCallback((id: number) => {
        setHoveredId(id);
    }, []);

    const handleRankingHoverEnd = useCallback(() => {
        setHoveredId(null);
    }, []);

    const handleViewModeChange = useCallback((value: string) => {
        if (value === 'heat' || value === 'points') {
            setViewMode(value);
        }
    }, []);

    return (
        <>
            <Head title="Mapa de eleitores" />
            <div
                ref={mapShellRef}
                className="relative isolate z-0 h-[calc(100svh-var(--app-shell-height,3.5rem))] overflow-hidden bg-muted"
            >
                {!summary.configured ? (
                    <div className="grid h-full place-items-center p-6">
                        <Card className="max-w-md gap-3 p-6 text-center">
                            <SettingsIcon className="mx-auto size-8 text-muted-foreground" />
                            <h1 className="font-semibold">
                                {summary.reason === 'candidate_unmatched'
                                    ? 'Candidatura não localizada no TSE'
                                    : 'Número eleitoral não cadastrado'}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {summary.reason === 'candidate_unmatched' ? (
                                    <>
                                        O número eleitoral do gabinete está
                                        cadastrado, mas nenhuma candidatura
                                        sincronizada do TSE corresponde a ele
                                        para Vereador(a) neste município. Pode
                                        ser um número incorreto ou uma
                                        sincronização pendente — fale com a
                                        administração da plataforma.
                                    </>
                                ) : (
                                    <>
                                        Para ver o mapa de calor com os votos
                                        oficiais do TSE, a administração da
                                        plataforma precisa cadastrar o número
                                        eleitoral deste gabinete.
                                    </>
                                )}
                            </p>
                            <Button asChild size="sm" className="mx-auto">
                                <Link
                                    href={tenantUrl('/configuracoes/gabinete')}
                                >
                                    Ver detalhes em Configurações
                                </Link>
                            </Button>
                        </Card>
                    </div>
                ) : (
                    <>
                        {isHydrated ? (
                            <Suspense
                                fallback={
                                    <div className="grid h-full place-items-center text-sm text-muted-foreground">
                                        Carregando mapa de eleitores...
                                    </div>
                                }
                            >
                                <ElectoralHeatmapCanvas
                                    points={visiblePoints}
                                    selectedId={visibleSelectedId}
                                    hoveredId={hoveredId}
                                    onSelect={handleMapSelect}
                                    focusRequest={focusRequest}
                                    viewMode={viewMode}
                                    metric="votes"
                                    maxValue={maxVotes}
                                    totalVotes={summary.totalVotes}
                                    candidateName={
                                        summary.candidate?.name ?? 'candidato'
                                    }
                                    state={officeState}
                                />
                            </Suspense>
                        ) : (
                            <div className="grid h-full place-items-center text-sm text-muted-foreground">
                                Carregando mapa de eleitores...
                            </div>
                        )}

                        {/* Painel desktop: mesma posição/aparência de sempre. */}
                        <Card className="absolute top-4 left-4 z-[500] hidden max-h-[calc(100%-2rem)] w-[calc(100%-5.5rem)] max-w-sm gap-4 overflow-y-auto bg-card/95 p-4 backdrop-blur md:flex">
                            <MapPanelContent
                                summary={summary}
                                coverage={coverage}
                                viewMode={viewMode}
                                onViewModeChange={handleViewModeChange}
                                query={query}
                                onQueryChange={setQuery}
                                visibleRankedPoints={visibleRankedPoints}
                                hasMoreRanked={
                                    rankedPoints.length > RANKING_LIMIT
                                }
                                selectedId={selectedId}
                                onRankingSelect={handleRankingSelect}
                                onRankingHoverStart={handleRankingHoverStart}
                                onRankingHoverEnd={handleRankingHoverEnd}
                                rankingItemRefs={rankingItemRefs}
                            />
                        </Card>

                        {/* Painel mobile: gatilho compacto + drawer, sem
                            atrapalhar a visualização do mapa por padrão. */}
                        <Button
                            type="button"
                            variant="secondary"
                            className="absolute bottom-4 left-1/2 z-[500] -translate-x-1/2 gap-1.5 shadow-lg md:hidden"
                            onClick={() => setSheetOpen(true)}
                        >
                            <AltArrowUpIcon className="size-4" />
                            {summary.totalVotes.toLocaleString('pt-BR')} votos ·{' '}
                            {summary.locatedLocations} locais
                        </Button>
                        <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                            <SheetContent
                                side="bottom"
                                className="max-h-[85vh] gap-0 overflow-y-auto p-4"
                            >
                                <SheetTitle className="sr-only">
                                    Mapa de eleitores
                                </SheetTitle>
                                <SheetDescription className="sr-only">
                                    Resumo, filtros e ranking dos locais de
                                    votação do candidato.
                                </SheetDescription>
                                <div className="flex flex-col gap-4">
                                    <MapPanelContent
                                        summary={summary}
                                        coverage={coverage}
                                        viewMode={viewMode}
                                        onViewModeChange={handleViewModeChange}
                                        query={query}
                                        onQueryChange={setQuery}
                                        visibleRankedPoints={
                                            visibleRankedPoints
                                        }
                                        hasMoreRanked={
                                            rankedPoints.length > RANKING_LIMIT
                                        }
                                        selectedId={selectedId}
                                        onRankingSelect={handleRankingSelect}
                                        onRankingHoverStart={
                                            handleRankingHoverStart
                                        }
                                        onRankingHoverEnd={
                                            handleRankingHoverEnd
                                        }
                                        rankingItemRefs={rankingItemRefs}
                                    />
                                </div>
                            </SheetContent>
                        </Sheet>

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
                    </>
                )}
            </div>
        </>
    );
}

function MapPanelContent({
    summary,
    coverage,
    viewMode,
    onViewModeChange,
    query,
    onQueryChange,
    visibleRankedPoints,
    hasMoreRanked,
    selectedId,
    onRankingSelect,
    onRankingHoverStart,
    onRankingHoverEnd,
    rankingItemRefs,
}: {
    summary: ElectoralMapSummary;
    coverage: number | null;
    viewMode: MapViewMode;
    onViewModeChange: (value: string) => void;
    query: string;
    onQueryChange: (value: string) => void;
    visibleRankedPoints: ElectoralMapPoint[];
    hasMoreRanked: boolean;
    selectedId: number | null;
    onRankingSelect: (id: number) => void;
    onRankingHoverStart: (id: number) => void;
    onRankingHoverEnd: () => void;
    rankingItemRefs: React.RefObject<Map<number, HTMLButtonElement>>;
}) {
    return (
        <>
            <MapPanelTitle
                title="Mapa de eleitores"
                description={`${summary.candidate?.name ?? ''}${summary.candidate?.party ? ` (${summary.candidate.party})` : ''}${summary.election ? ` — ${summary.election.name}` : ''}`}
            />

            <div>
                <p className="mb-1 text-[11px] font-medium text-muted-foreground">
                    Visualização
                </p>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    spacing={0}
                    value={viewMode}
                    onValueChange={onViewModeChange}
                >
                    <ToggleGroupItem
                        value="heat"
                        aria-label="Modo calor"
                        className="gap-1.5 text-xs"
                    >
                        <FlameIcon className="size-3.5" />
                        Calor
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="points"
                        aria-label="Modo pontos"
                        className="gap-1.5 text-xs"
                    >
                        <RecordIcon className="size-3.5" />
                        Pontos
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div className="grid grid-cols-3 gap-2 text-center">
                <Metric
                    value={summary.totalVotes.toLocaleString('pt-BR')}
                    label="Votos"
                />
                <Metric
                    value={`${summary.locatedLocations} / ${summary.totalLocations}`}
                    label="Locais"
                />
                <Metric
                    value={coverage !== null ? formatCoverage(coverage) : '—'}
                    label="Cobertura"
                />
            </div>

            <div className="relative">
                <MagnifierIcon className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                <Input
                    value={query}
                    onChange={(event) => onQueryChange(event.target.value)}
                    className="pr-9 pl-9"
                    placeholder="Nome do local ou bairro"
                    aria-label="Buscar local de votação no mapa"
                />
                {query && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute top-1 right-1 size-7"
                        onClick={() => onQueryChange('')}
                        aria-label="Limpar busca"
                    >
                        <CloseIcon className="size-4" />
                    </Button>
                )}
            </div>

            {visibleRankedPoints.length > 0 && (
                <div className="divide-y overflow-hidden rounded-md border">
                    {visibleRankedPoints.map((point) => {
                        const selected = point.id === selectedId;

                        return (
                            <button
                                key={point.id}
                                ref={(instance) => {
                                    if (!instance) {
                                        return;
                                    }

                                    rankingItemRefs.current.set(
                                        point.id,
                                        instance,
                                    );

                                    return () => {
                                        rankingItemRefs.current.delete(
                                            point.id,
                                        );
                                    };
                                }}
                                type="button"
                                className={cn(
                                    'flex w-full cursor-pointer items-center gap-2 px-2 py-2.5 text-left transition-colors hover:bg-muted',
                                    selected && 'bg-muted',
                                )}
                                onClick={() => onRankingSelect(point.id)}
                                onMouseEnter={() =>
                                    onRankingHoverStart(point.id)
                                }
                                onMouseLeave={onRankingHoverEnd}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-xs font-medium uppercase">
                                        {point.name}
                                    </span>
                                    <span className="block truncate text-[11px] text-muted-foreground">
                                        {[point.address, point.neighborhood]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <span className="shrink-0 font-mono text-lg font-bold tabular-nums">
                                    {point.votes.toLocaleString('pt-BR')}
                                </span>
                            </button>
                        );
                    })}
                    {hasMoreRanked && (
                        <p className="px-2 py-2.5 text-xs text-muted-foreground">
                            Refine a busca para ver outros locais.
                        </p>
                    )}
                </div>
            )}

            {summary.pendingGeocoding > 0 && (
                <Badge variant="outline">
                    {summary.pendingGeocoding} local(is) aguardando
                    geocodificação de endereço.
                </Badge>
            )}
        </>
    );
}

function Metric({ value, label }: { value: string; label: string }) {
    return (
        <div className="rounded-lg bg-muted p-2">
            <strong className="block font-mono text-lg font-bold tabular-nums">
                {value}
            </strong>
            <span className="text-[11px] text-muted-foreground">{label}</span>
        </div>
    );
}

ElectoralMap.layout = {
    breadcrumbs: [{ title: 'Mapa de eleitores', href: '/eleitores/mapa' }],
};
