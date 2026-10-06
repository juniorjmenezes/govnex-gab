import { cn } from '@/lib/utils';

export interface SeatParty {
    party: string;
    seats: number;
    color: string | null;
}

const FALLBACK_COLOR = 'var(--muted-foreground)';
/** Cadeiras de outros estados (ou ainda não definidas): cinza visível, mas
 * sem competir com as cores dos partidos. */
const GRAY = 'color-mix(in oklab, var(--muted-foreground) 35%, transparent)';

const CENTER_X = 50;
const CENTER_Y = 50;
const OUTER_RADIUS = 44;
const INNER_RATIO = 0.42;
/** Até esse total, todas as cadeiras têm o mesmo tamanho (Senado, Assembleia). */
const FIXED_DOT_MAX_SEATS = 100;
const FIXED_DOT_RADIUS = 2.6;

/**
 * Escolhe quantas fileiras usar para que a distância entre fileiras e a
 * distância entre cadeiras de uma mesma fileira sejam parecidas — assim os
 * pontos ficam em grade regular, sem se sobrepor, qualquer que seja o total
 * (81 no Senado, 513 na Câmara, 46 numa Assembleia).
 */
function seatLayout(total: number) {
    const innerRadius = OUTER_RADIUS * INNER_RATIO;
    const span = OUTER_RADIUS - innerRadius;
    const fixedDots = total <= FIXED_DOT_MAX_SEATS;
    let best = { rows: 1, score: Number.POSITIVE_INFINITY };

    for (let rows = 1; rows <= Math.min(24, total); rows += 1) {
        const radii = Array.from({ length: rows }, (_, i) =>
            rows === 1 ? OUTER_RADIUS : innerRadius + (span * i) / (rows - 1),
        );
        const radiusSum = radii.reduce((sum, r) => sum + r, 0);
        // Distância média entre cadeiras num arco de raio r com n cadeiras é
        // πr/n; como n ∝ r, ela é constante entre fileiras: π·Σr/N.
        const seatSpacing = (Math.PI * radiusSum) / total;
        const rowSpacing =
            rows === 1 ? Number.POSITIVE_INFINITY : span / (rows - 1);
        // Com raio fixo (casas pequenas), queremos o maior espaço livre
        // possível; com raio adaptativo (Câmara), o espaçamento mais parelho.
        const score = fixedDots
            ? -Math.min(seatSpacing, rowSpacing)
            : Math.abs(seatSpacing - rowSpacing);

        if (score < best.score) {
            best = { rows, score };
        }
    }

    const rows = best.rows;
    const radii = Array.from({ length: rows }, (_, i) =>
        rows === 1 ? OUTER_RADIUS : innerRadius + (span * i) / (rows - 1),
    );
    const radiusSum = radii.reduce((sum, r) => sum + r, 0);
    const seatSpacing = (Math.PI * radiusSum) / total;
    const rowSpacing = rows === 1 ? seatSpacing : span / (rows - 1);
    // Mesmo tamanho de ponto nas casas pequenas (Senado, Assembleia); na
    // Câmara, o ponto diminui até caber.
    const dotRadius = fixedDots
        ? FIXED_DOT_RADIUS
        : Math.min(seatSpacing, rowSpacing) * 0.4;

    const perRow = radii.map((r) =>
        Math.max(1, Math.round((total * r) / radiusSum)),
    );
    let diff = total - perRow.reduce((sum, n) => sum + n, 0);

    for (
        let i = perRow.length - 1;
        diff !== 0;
        i = (i - 1 + perRow.length) % perRow.length
    ) {
        if (diff > 0) {
            perRow[i] += 1;
            diff -= 1;
        } else if (perRow[i] > 1) {
            perRow[i] -= 1;
            diff += 1;
        }
    }

    const positions: { x: number; y: number; angle: number }[] = [];
    radii.forEach((r, row) => {
        const count = perRow[row];

        for (let k = 0; k < count; k += 1) {
            const angle =
                count === 1
                    ? Math.PI / 2
                    : Math.PI - (Math.PI * k) / (count - 1);
            positions.push({
                x: CENTER_X + r * Math.cos(angle),
                y: CENTER_Y - r * Math.sin(angle),
                angle,
            });
        }
    });

    // Da esquerda para a direita: cada partido ocupa um setor contínuo.
    positions.sort((a, b) => b.angle - a.angle || a.x - b.x);

    return { positions, dotRadius };
}

/**
 * Semicírculo da casa: as cadeiras eleitas no estado do gabinete coloridas
 * por partido, e as demais (outros estados) em cinza. Cores vêm de
 * `PartidoCor`.
 */
export function SeatHemicycle({
    parties,
    chamberTotal,
    uf,
    className,
}: {
    /** Cadeiras eleitas no estado do gabinete, por partido. */
    parties: SeatParty[];
    /** Tamanho da casa inteira; o restante fica em cinza. */
    chamberTotal?: number | null;
    uf?: string;
    className?: string;
}) {
    const elected = parties.reduce((sum, p) => sum + p.seats, 0);
    const total = Math.max(elected, chamberTotal ?? elected);
    const others = total - elected;

    if (total === 0) {
        return (
            <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                Nenhuma cadeira definida ainda.
            </p>
        );
    }

    const { positions, dotRadius } = seatLayout(total);
    const colors = [
        ...parties.flatMap((p) =>
            Array.from({ length: p.seats }, () => p.color ?? FALLBACK_COLOR),
        ),
        ...Array.from({ length: others }, () => GRAY),
    ];

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            {uf && (
                <p className="text-center text-xs text-muted-foreground">
                    Cadeiras de {uf} em destaque; o restante da casa em cinza.
                </p>
            )}
            <svg
                viewBox="0 0 100 56"
                className="mx-auto w-full max-w-sm"
                role="img"
                aria-label={parties
                    .map((p) => `${p.party}: ${p.seats}`)
                    .join(', ')}
            >
                {positions.map((pos, i) => (
                    <circle
                        key={i}
                        cx={pos.x}
                        cy={pos.y}
                        r={dotRadius}
                        style={{ fill: colors[i] }}
                    />
                ))}
            </svg>
            <ul className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                {others > 0 && (
                    <li className="flex items-center justify-between gap-2">
                        <span className="flex min-w-0 items-center gap-2">
                            <span
                                className="size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: GRAY }}
                                aria-hidden="true"
                            />
                            <span className="truncate font-medium text-muted-foreground">
                                Demais estados
                            </span>
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {others}
                        </span>
                    </li>
                )}
                {parties.map((item) => (
                    <li
                        key={item.party}
                        className="flex items-center justify-between gap-2"
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <span
                                className="size-2.5 shrink-0 rounded-full"
                                style={{
                                    backgroundColor:
                                        item.color ?? FALLBACK_COLOR,
                                }}
                                aria-hidden="true"
                            />
                            <span className="truncate font-medium">
                                {item.party}
                            </span>
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {item.seats}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
