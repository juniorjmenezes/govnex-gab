import * as L from 'leaflet';
import { LatLngBounds } from 'leaflet';
import 'leaflet.heat';
import { useEffect, useMemo, useRef } from 'react';
import {
    CircleMarker,
    MapContainer,
    Popup,
    ScaleControl,
    TileLayer,
    useMap,
    ZoomControl,
} from 'react-leaflet';
import {
    buildHeatLayerGradient,
    intensityColor,
    intensityRadius,
    metricIntensity,
    readPrimaryOklch,
} from '@/lib/electoral-heatmap-scale';
import type { Oklch } from '@/lib/electoral-heatmap-scale';
import type { MapMetric } from '@/lib/electoral-map-metrics';
import { mapMetrics } from '@/lib/electoral-map-metrics';
import { emptyMapView, fitOptions } from '@/lib/map-viewport';
import type { ElectoralMapPoint } from '@/types';
import 'leaflet/dist/leaflet.css';
// Precisa vir depois de leaflet.css para sobrescrever o balão padrão do
// Leaflet com a aparência do app (ver comentário no topo do arquivo).
import '../../../css/leaflet-popup.css';

export type MapViewMode = 'heat' | 'points';
export type MapFocusRequest = { id: number; nonce: number } | null;

function HeatLayer({
    points,
    metric,
    maxValue,
    totalVotes,
    primary,
}: {
    points: ElectoralMapPoint[];
    metric: MapMetric;
    maxValue: number;
    totalVotes: number;
    primary: Oklch;
}) {
    const map = useMap();

    useEffect(() => {
        if (points.length === 0) {
            return;
        }

        const getValue = mapMetrics[metric].getValue;

        // radius/blur contidos de propósito: o leaflet.heat funde numa
        // única célula os locais que caem a menos de ~metade desse raio um
        // do outro, somando os pesos (limitado à intensidade máxima). Um
        // raio grande faria vários locais próximos e individualmente
        // modestos se fundirem e parecerem tão "quentes" quanto o local
        // isoladamente mais votado — por isso o valor é moderado, não o
        // padrão do plugin.
        const layer = L.heatLayer(
            points.map((point): [number, number, number] => [
                point.latitude,
                point.longitude,
                metricIntensity(getValue(point, totalVotes), maxValue),
            ]),
            {
                radius: 22,
                blur: 18,
                maxZoom: 17,
                minOpacity: 0.28,
                gradient: buildHeatLayerGradient(primary),
            },
        ).addTo(map);

        // leaflet.heat sempre anexa seu canvas ao overlayPane sem marcá-lo
        // como pointer-events: none, então ele cobre o mapa inteiro e
        // engole clique/hover antes de chegarem aos CircleMarker abaixo.
        // Como a camada é só decorativa (não tem handlers próprios), é
        // seguro deixá-la transparente a eventos de ponteiro.
        const heatCanvas = map
            .getPane('overlayPane')
            ?.querySelector<HTMLElement>('.leaflet-heatmap-layer');

        if (heatCanvas) {
            heatCanvas.style.pointerEvents = 'none';
        }

        return () => {
            map.removeLayer(layer);
        };
    }, [map, points, metric, maxValue, totalVotes, primary]);

    return null;
}

function MapViewport({
    points,
    state,
}: {
    points: ElectoralMapPoint[];
    state?: string | null;
}) {
    const map = useMap();

    useEffect(() => {
        if (points.length === 0) {
            const view = emptyMapView(state);
            map.setView(view.center, view.zoom);

            return;
        }

        if (points.length === 1) {
            map.setView([points[0].latitude, points[0].longitude], 16);

            return;
        }

        map.fitBounds(
            new LatLngBounds(
                points.map((point) => [point.latitude, point.longitude]),
            ),
            fitOptions(),
        );
    }, [map, points, state]);

    return null;
}

/**
 * Comanda flyTo + abertura de popup quando o usuário clica um item do
 * ranking lateral (não quando clica direto num ponto do mapa — nesse caso o
 * próprio Leaflet já abre o popup e não faz sentido "recentralizar" o mapa
 * embaixo do clique do usuário). Depende só de `focusRequest`: o nonce muda
 * a cada clique no ranking, mesmo que seja o mesmo local de novo.
 */
function FocusHandler({
    focusRequest,
    points,
    markers,
}: {
    focusRequest: MapFocusRequest;
    points: ElectoralMapPoint[];
    markers: React.RefObject<Map<number, L.CircleMarker>>;
}) {
    const map = useMap();
    const pointsRef = useRef(points);

    useEffect(() => {
        pointsRef.current = points;
    }, [points]);

    useEffect(() => {
        if (!focusRequest) {
            return;
        }

        const point = pointsRef.current.find(
            (item) => item.id === focusRequest.id,
        );

        if (!point) {
            return;
        }

        map.flyTo(
            [point.latitude, point.longitude],
            Math.max(map.getZoom(), 16),
            { duration: 0.6 },
        );

        // Espera o flyTo começar antes de abrir o popup — abrir de imediato
        // pode deixá-lo mal posicionado durante a animação.
        const timeout = window.setTimeout(() => {
            markers.current.get(focusRequest.id)?.openPopup();
        }, 400);

        return () => window.clearTimeout(timeout);
    }, [focusRequest, map, markers]);

    return null;
}

export default function ElectoralHeatmapCanvas({
    points,
    selectedId,
    hoveredId,
    onSelect,
    focusRequest,
    viewMode,
    metric,
    maxValue,
    totalVotes,
    candidateName,
    state,
}: {
    points: ElectoralMapPoint[];
    selectedId: number | null;
    hoveredId: number | null;
    onSelect: (id: number) => void;
    focusRequest: MapFocusRequest;
    viewMode: MapViewMode;
    metric: MapMetric;
    /**
     * Máximo do valor da métrica ativa no conjunto completo de locais (não
     * só dos visíveis após uma busca), para que a escala de cor/tamanho não
     * mude conforme o usuário filtra e continue comparável com a legenda.
     */
    maxValue: number;
    totalVotes: number;
    candidateName: string;
    state?: string | null;
}) {
    const markersRef = useRef(new Map<number, L.CircleMarker>());
    // Lida uma vez por montagem: pega a cor institucional do gabinete
    // (Gabinete.cor_principal, aplicada pelo OfficeTheme) quando cadastrada,
    // senão o teal padrão do tema (claro/escuro) — sem reconsultar o CSS a
    // cada marcador.
    const primary = useMemo(() => readPrimaryOklch(), []);
    const initialView = emptyMapView(state);
    const decoratedPoints = useMemo(() => {
        const getValue = mapMetrics[metric].getValue;

        return points
            .map((point) => {
                const value = getValue(point, totalVotes);

                return {
                    point,
                    value,
                    intensity: metricIntensity(value, maxValue),
                };
            })
            .sort((first, second) => first.value - second.value);
    }, [points, metric, totalVotes, maxValue]);

    return (
        <MapContainer
            center={initialView.center}
            zoom={initialView.zoom}
            zoomControl={false}
            preferCanvas
            className="h-full w-full"
        >
            <TileLayer
                attribution={
                    import.meta.env.VITE_MAP_ATTRIBUTION ??
                    '&copy; OpenStreetMap contributors'
                }
                url={
                    import.meta.env.VITE_MAP_TILE_URL ??
                    'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
                }
            />
            <ZoomControl position="bottomright" />
            <ScaleControl position="bottomleft" imperial={false} />
            <MapViewport points={points} state={state} />
            <FocusHandler
                focusRequest={focusRequest}
                points={points}
                markers={markersRef}
            />
            {viewMode === 'heat' && (
                <HeatLayer
                    points={points}
                    metric={metric}
                    maxValue={maxValue}
                    totalVotes={totalVotes}
                    primary={primary}
                />
            )}
            {decoratedPoints.map(({ point, intensity }) => {
                const selected = point.id === selectedId;
                const hovered = point.id === hoveredId && !selected;
                // Raio maior que o "necessário" visualmente: no modo Calor
                // em especial, um ponto de 4px é quase impossível de acertar
                // com o mouse — o alvo clicável do Leaflet segue o raio
                // desenhado, então aumentar o raio aumenta as duas coisas
                // junto.
                const baseRadius =
                    viewMode === 'points'
                        ? intensityRadius(intensity)
                        : selected
                          ? 12
                          : 8;
                const radius = baseRadius + (hovered ? 3 : 0);
                const percentage = mapMetrics.percentage.getValue(
                    point,
                    totalVotes,
                );

                return (
                    <CircleMarker
                        key={point.id}
                        ref={(instance) => {
                            if (!instance) {
                                return;
                            }

                            markersRef.current.set(point.id, instance);

                            return () => {
                                markersRef.current.delete(point.id);
                            };
                        }}
                        center={[point.latitude, point.longitude]}
                        radius={radius}
                        pathOptions={{
                            // Contorno escuro no selecionado/hover para
                            // destacar contra os tiles do mapa, independente
                            // do tom (claro ou escuro) do preenchimento.
                            color: selected || hovered ? '#111827' : '#ffffff',
                            fillColor: intensityColor(intensity, primary),
                            fillOpacity: selected ? 0.95 : 0.75,
                            opacity: 1,
                            weight: selected ? 2.5 : hovered ? 2 : 1,
                        }}
                        eventHandlers={{
                            click: () => onSelect(point.id),
                        }}
                    >
                        <Popup>
                            <div className="min-w-48 space-y-1.5">
                                <p className="text-xs font-semibold">
                                    {point.name}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    {[point.address, point.neighborhood]
                                        .filter(Boolean)
                                        .join(' · ') ||
                                        'Endereço não informado'}
                                </p>
                                <div className="flex items-center gap-1.5 border-t pt-2 text-xs font-medium">
                                    <span
                                        className="size-2 shrink-0 rounded-full ring-1 ring-black/10"
                                        style={{
                                            backgroundColor: intensityColor(
                                                intensity,
                                                primary,
                                            ),
                                        }}
                                        aria-hidden="true"
                                    />
                                    {point.votes.toLocaleString('pt-BR')}{' '}
                                    {point.votes === 1 ? 'voto' : 'votos'}
                                </div>
                                {totalVotes > 0 && (
                                    <p className="text-[11px] text-muted-foreground">
                                        {mapMetrics.percentage.format(
                                            percentage,
                                        )}{' '}
                                        dos votos de {candidateName}
                                    </p>
                                )}
                                {point.sections > 0 && (
                                    <p className="text-[11px] text-muted-foreground">
                                        {point.sections}{' '}
                                        {point.sections === 1
                                            ? 'seção eleitoral'
                                            : 'seções eleitorais'}
                                    </p>
                                )}
                            </div>
                        </Popup>
                    </CircleMarker>
                );
            })}
        </MapContainer>
    );
}
