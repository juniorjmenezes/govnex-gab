// Escala visual compartilhada entre o borrão de calor e os pontos (modo
// "Pontos") do mapa eleitoral. Funciona para qualquer métrica selecionada
// (ver electoral-map-metrics.ts) — recebe sempre um valor já calculado pela
// métrica ativa e o máximo desse mesmo valor no conjunto.
//
// A cor é um degradê de matiz única: claro = poucos votos, escuro = muitos
// votos. A matiz vem de --primary em tempo real — que é a cor institucional
// do gabinete (Gabinete.cor_principal) quando cadastrada, já que
// OfficeTheme (components/layout/office-theme.tsx) sobrescreve essa
// variável com o hex do gabinete; cai para a cor padrão do sistema (o teal
// de resources/css/app.css) quando o gabinete não personalizou nada.

export type Oklch = { l: number; c: number; h: number };

// Mesmo valor de --primary (tema claro) em resources/css/app.css, usado só
// como fallback quando o CSS ainda não carregou ou fora do navegador (SSR).
const FALLBACK_PRIMARY_OKLCH: Oklch = { l: 0.511, c: 0.096, h: 186.391 };

/**
 * Lê a cor primária ativa no momento (clara/escura conforme .dark, ou a cor
 * institucional do gabinete quando o OfficeTheme a sobrescreveu). Aceita os
 * dois formatos que --primary pode assumir nesse app: oklch(...) (tokens de
 * resources/css/app.css) ou #rrggbb (setado inline pelo OfficeTheme a partir
 * de Gabinete.cor_principal).
 */
export function readPrimaryOklch(): Oklch {
    if (typeof document === 'undefined') {
        return FALLBACK_PRIMARY_OKLCH;
    }

    const raw = getComputedStyle(document.documentElement)
        .getPropertyValue('--primary')
        .trim();

    const hexMatch = raw.match(/^#([0-9a-f]{6})$/i);

    if (hexMatch) {
        return hexToOklch(raw);
    }

    const oklchMatch = raw.match(/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)/i);

    if (oklchMatch) {
        return {
            l: Number(oklchMatch[1]),
            c: Number(oklchMatch[2]),
            h: Number(oklchMatch[3]),
        };
    }

    return FALLBACK_PRIMARY_OKLCH;
}

/**
 * sRGB → OKLCH (fórmulas padrão de Björn Ottosson / CSS Color 4). Usada só
 * para entender a cor institucional do gabinete (#rrggbb) na mesma escala
 * OKLCH do resto da função — inversa de oklchToRgbString abaixo.
 */
function hexToOklch(hex: string): Oklch {
    const toLinear = (channel: number): number =>
        channel <= 0.04045
            ? channel / 12.92
            : Math.pow((channel + 0.055) / 1.055, 2.4);

    const r = toLinear(Number.parseInt(hex.slice(1, 3), 16) / 255);
    const g = toLinear(Number.parseInt(hex.slice(3, 5), 16) / 255);
    const b = toLinear(Number.parseInt(hex.slice(5, 7), 16) / 255);

    const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b;
    const m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b;
    const s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b;

    const l_ = Math.cbrt(l);
    const m_ = Math.cbrt(m);
    const s_ = Math.cbrt(s);

    const lOklab = 0.210454268 * l_ + 0.7936177747 * m_ - 0.0040720468 * s_;
    const a = 1.9779985324 * l_ - 2.4285922051 * m_ + 0.4505937099 * s_;
    const bOklab = 0.0259040371 * l_ + 0.7827717662 * m_ - 0.808675766 * s_;

    const c = Math.sqrt(a * a + bOklab * bOklab);
    const hRadians = Math.atan2(bOklab, a);
    const h = ((hRadians * 180) / Math.PI + 360) % 360;

    return { l: lOklab, c, h };
}

/**
 * OKLCH → sRGB (fórmulas padrão de Björn Ottosson, as mesmas do CSS Color
 * 4). Convertemos para rgb() em vez de emitir oklch() diretamente porque o
 * renderer Canvas do Leaflet (preferCanvas) precisa de um valor que todo
 * navegador aceite como fillStyle.
 */
function oklchToRgbString(l: number, c: number, hueDegrees: number): string {
    const hueRadians = (hueDegrees * Math.PI) / 180;
    const a = c * Math.cos(hueRadians);
    const b = c * Math.sin(hueRadians);

    const lms = l + 0.3963377774 * a + 0.2158037573 * b;
    const ms = l - 0.1055613458 * a - 0.0638541728 * b;
    const ss = l - 0.0894841775 * a - 1.291485548 * b;

    const l3 = lms ** 3;
    const m3 = ms ** 3;
    const s3 = ss ** 3;

    const rLinear = 4.0767416621 * l3 - 3.3077115913 * m3 + 0.2309699292 * s3;
    const gLinear = -1.2684380046 * l3 + 2.6097574011 * m3 - 0.3413193965 * s3;
    const bLinear = -0.0041960863 * l3 - 0.7034186147 * m3 + 1.707614701 * s3;

    const toChannel = (value: number): number => {
        const clamped = Math.min(Math.max(value, 0), 1);
        const srgb =
            clamped <= 0.0031308
                ? 12.92 * clamped
                : 1.055 * Math.pow(clamped, 1 / 2.4) - 0.055;

        return Math.round(srgb * 255);
    };

    return `rgb(${toChannel(rLinear)}, ${toChannel(gLinear)}, ${toChannel(bLinear)})`;
}

/**
 * Normaliza um valor (votos, % ou outra métrica futura) para 0–1 usando raiz
 * quadrada em vez de razão linear: espalha melhor os valores intermediários
 * (ex.: 108 e 54 votos deixam de ficar "grudados" perto de zero quando o
 * máximo do conjunto é bem maior, como 415).
 */
export function metricIntensity(value: number, maxValue: number): number {
    if (maxValue <= 0) {
        return 0;
    }

    return Math.sqrt(Math.min(Math.max(value, 0), maxValue) / maxValue);
}

// Ponta clara fixa (não parte de --primary): mantém o menor valor sempre
// perceptível como um tom pálido da cor, em vez de quase branco — o que
// somaria ao fundo claro dos tiles do mapa e sumiria de vista.
const LIGHT_L = 0.88;
const LIGHT_C_RATIO = 0.22;

export function intensityColor(intensity: number, primary: Oklch): string {
    const t = Math.min(Math.max(intensity, 0), 1);
    const lightC = primary.c * LIGHT_C_RATIO;
    // Mais escuro que a própria --primary (que é calibrada para texto/ícone
    // sobre fundo claro, não para ser o tom "máximo" de uma escala), com piso
    // para não virar praticamente preto e perder a matiz.
    const darkL = Math.max(Math.min(primary.l, 0.5) * 0.6, 0.28);
    const darkC = primary.c * 1.3;

    const l = LIGHT_L + (darkL - LIGHT_L) * t;
    const c = lightC + (darkC - lightC) * t;

    return oklchToRgbString(l, c, primary.h);
}

/**
 * Raio (px) de um círculo proporcional no modo "Pontos", também em escala de
 * raiz quadrada — área do círculo cresce proporcional ao valor, não o raio
 * diretamente, evitando que o maior local vire um círculo desproporcional.
 */
export function intensityRadius(intensity: number, min = 8, max = 28): number {
    return min + (max - min) * Math.min(Math.max(intensity, 0), 1);
}

// Gradiente para a camada L.heatLayer (o borrão de calor no modo "Calor"),
// amostrado da mesma escala acima para não destoar dos pontos.
export function buildHeatLayerGradient(primary: Oklch): Record<number, string> {
    return {
        0.2: intensityColor(0.2, primary),
        0.4: intensityColor(0.4, primary),
        0.6: intensityColor(0.6, primary),
        0.8: intensityColor(0.8, primary),
        1: intensityColor(1, primary),
    };
}
