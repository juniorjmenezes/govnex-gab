import { Link } from '@inertiajs/react';
import { LatLngBounds } from 'leaflet';
import { useEffect } from 'react';
import {
    CircleMarker,
    MapContainer,
    Popup,
    ScaleControl,
    TileLayer,
    useMap,
    ZoomControl,
} from 'react-leaflet';
import type { VoterMapMarker } from '@/types';
import 'leaflet/dist/leaflet.css';
// Precisa vir depois de leaflet.css para sobrescrever o balão padrão do
// Leaflet com a aparência do app (ver resources/css/leaflet-popup.css).
import '../../../css/leaflet-popup.css';

function MapViewport({
    markers,
    selectedId,
}: {
    markers: VoterMapMarker[];
    selectedId: number | null;
}) {
    const map = useMap();

    useEffect(() => {
        const selected = markers.find((marker) => marker.id === selectedId);

        if (selected) {
            map.flyTo([selected.latitude, selected.longitude], 17, {
                duration: 0.7,
            });

            return;
        }

        if (markers.length === 0) {
            map.setView([-14.235, -51.9253], 4);

            return;
        }

        if (markers.length === 1) {
            map.setView([markers[0].latitude, markers[0].longitude], 16);

            return;
        }

        map.fitBounds(
            new LatLngBounds(
                markers.map((marker) => [marker.latitude, marker.longitude]),
            ),
            { padding: [48, 48], maxZoom: 16 },
        );
    }, [map, markers, selectedId]);

    return null;
}

export default function ProspectingMapCanvas({
    markers,
    selectedId,
    onSelect,
}: {
    markers: VoterMapMarker[];
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    return (
        <MapContainer
            center={[-14.235, -51.9253]}
            zoom={4}
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
            <MapViewport markers={markers} selectedId={selectedId} />
            {markers.map((marker) => {
                const selected = marker.id === selectedId;

                return (
                    <CircleMarker
                        key={marker.id}
                        center={[marker.latitude, marker.longitude]}
                        radius={selected ? 10 : 7}
                        pathOptions={{
                            color: selected ? '#dc2626' : '#ffffff',
                            fillColor: selected ? '#ef4444' : '#2563eb',
                            fillOpacity: 0.9,
                            opacity: 1,
                            weight: selected ? 3 : 2,
                        }}
                        eventHandlers={{
                            click: () => onSelect(marker.id),
                        }}
                    >
                        <Popup>
                            <div className="min-w-48 space-y-1">
                                <strong className="block">{marker.name}</strong>
                                <span className="block text-xs text-muted-foreground">
                                    {[marker.address, marker.neighborhood]
                                        .filter(Boolean)
                                        .join(' — ') ||
                                        'Endereço não informado'}
                                </span>
                                <Link
                                    href={`/cidadaos/${marker.id}`}
                                    className="inline-block pt-1 text-sm font-medium text-primary hover:underline"
                                >
                                    Abrir perfil
                                </Link>
                            </div>
                        </Popup>
                    </CircleMarker>
                );
            })}
        </MapContainer>
    );
}
