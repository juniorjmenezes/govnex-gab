import { Icon } from 'leaflet';
import type { LeafletMouseEvent, Marker as LeafletMarker } from 'leaflet';
import markerIconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png';
import markerIconUrl from 'leaflet/dist/images/marker-icon.png';
import markerShadowUrl from 'leaflet/dist/images/marker-shadow.png';
import { useEffect, useMemo, useRef } from 'react';
import {
    MapContainer,
    Marker,
    TileLayer,
    useMap,
    useMapEvents,
} from 'react-leaflet';
import 'leaflet/dist/leaflet.css';

type Coordinates = {
    latitude: number;
    longitude: number;
};

const markerIcon = new Icon({
    iconUrl: markerIconUrl,
    iconRetinaUrl: markerIconRetinaUrl,
    shadowUrl: markerShadowUrl,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    shadowSize: [41, 41],
});

function MapInteraction({
    onChange,
}: {
    onChange: (coordinates: Coordinates) => void;
}) {
    useMapEvents({
        click(event: LeafletMouseEvent) {
            onChange({
                latitude: event.latlng.lat,
                longitude: event.latlng.lng,
            });
        },
    });

    return null;
}

function MapCenter({ coordinates }: { coordinates: Coordinates }) {
    const map = useMap();

    useEffect(() => {
        map.setView([coordinates.latitude, coordinates.longitude], 16);
    }, [coordinates, map]);

    return null;
}

export default function CitizenLocationMap({
    coordinates,
    onChange,
}: {
    coordinates: Coordinates;
    onChange?: (coordinates: Coordinates) => void;
}) {
    const markerRef = useRef<LeafletMarker>(null);
    const eventHandlers = useMemo(
        () => ({
            dragend() {
                const marker = markerRef.current;

                if (marker && onChange) {
                    const position = marker.getLatLng();
                    onChange({
                        latitude: position.lat,
                        longitude: position.lng,
                    });
                }
            },
        }),
        [onChange],
    );

    return (
        <div className="relative isolate z-0 overflow-hidden rounded-2xl border">
            <MapContainer
                center={[coordinates.latitude, coordinates.longitude]}
                zoom={16}
                scrollWheelZoom
                className="h-72 w-full"
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
                <MapCenter coordinates={coordinates} />
                {onChange && <MapInteraction onChange={onChange} />}
                <Marker
                    ref={markerRef}
                    position={[coordinates.latitude, coordinates.longitude]}
                    icon={markerIcon}
                    draggable={Boolean(onChange)}
                    eventHandlers={eventHandlers}
                />
            </MapContainer>
        </div>
    );
}
