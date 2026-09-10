import type { FitBoundsOptions, LatLngTuple } from 'leaflet';

/**
 * Centro aproximado de cada UF, usado só como enquadramento inicial quando o
 * gabinete ainda não tem nada para plotar. Antes disso o mapa abria no centro
 * geográfico do Brasil em zoom 4, mostrando meio continente e nenhum contexto
 * de onde o gabinete fica.
 */
const STATE_CENTERS: Record<string, LatLngTuple> = {
    AC: [-9.02, -70.53],
    AL: [-9.57, -36.78],
    AM: [-3.98, -63.79],
    AP: [1.41, -51.77],
    BA: [-12.96, -41.7],
    CE: [-5.2, -39.53],
    DF: [-15.78, -47.93],
    ES: [-19.57, -40.65],
    GO: [-15.98, -49.86],
    MA: [-5.42, -45.44],
    MG: [-18.1, -44.38],
    MS: [-20.51, -54.54],
    MT: [-12.64, -55.42],
    PA: [-5.53, -52.29],
    PB: [-7.28, -36.72],
    PE: [-8.38, -37.86],
    PI: [-6.6, -42.28],
    PR: [-24.89, -51.55],
    RJ: [-22.25, -42.66],
    RN: [-5.81, -36.59],
    RO: [-10.83, -63.34],
    RR: [1.99, -61.33],
    RS: [-30.17, -53.5],
    SC: [-27.45, -50.95],
    SE: [-10.57, -37.45],
    SP: [-22.19, -48.79],
    TO: [-9.46, -48.26],
};

const BRAZIL_CENTER: LatLngTuple = [-14.235, -51.9253];

/** Enquadramento de partida quando não há pontos: UF do gabinete ou país. */
export function emptyMapView(state?: string | null): {
    center: LatLngTuple;
    zoom: number;
} {
    const center = state ? STATE_CENTERS[state.toUpperCase()] : undefined;

    return center ? { center, zoom: 7 } : { center: BRAZIL_CENTER, zoom: 4 };
}

/**
 * O painel flutuante cobre o canto superior esquerdo do mapa a partir de
 * `md`. Sem esta folga o `fitBounds` centraliza os pontos na área inteira e
 * parte deles fica atrás do painel.
 */
export function fitOptions(): FitBoundsOptions {
    const panelWidth =
        typeof window !== 'undefined' && window.innerWidth >= 768 ? 384 : 0;

    return {
        paddingTopLeft: [panelWidth + 32, 32],
        paddingBottomRight: [32, 32],
        maxZoom: 16,
    };
}
