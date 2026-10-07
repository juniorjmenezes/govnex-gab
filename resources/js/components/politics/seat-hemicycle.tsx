import { cn } from '@/lib/utils';

export interface SeatParty {
    party: string;
    seats: number;
    /** Cadeiras desse partido que são do estado do gabinete — presente só em
     * bancadas nacionais (Senado, Deputado Federal). Sem isso, o componente
     * assume o modo antigo: só as cadeiras da própria UF, resto em cinza. */
    home_seats?: number;
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

    return { positions, dotRadius, viewBoxHeight: 56 };
}

/** Margem interna do viewBox nas duas grades (linhas e semicírculo) usam a
 * mesma área útil, então os dois modos ficam visualmente alinhados. */
const GRID_MARGIN_X = 3;
const GRID_MARGIN_Y = 3;

/** Espaço (centro a centro) entre cadeiras na grade em linhas — fixo, pelo
 * mesmo raio usado no semicírculo pequeno, para o ponto ter sempre o mesmo
 * tamanho em qualquer um dos gráficos de composição. */
const GRID_CELL_SIZE = FIXED_DOT_RADIUS * 2 * 1.15;

/**
 * Casas muito grandes (Deputado Federal, 513 cadeiras) ficam com o ponto
 * encolhido e de tamanho inconsistente entre fileiras no semicírculo — o
 * raio ali varia conforme a fileira para preencher o arco. Em linha reta, o
 * raio é sempre {@link FIXED_DOT_RADIUS}, igual ao do Senado/Assembleia; o
 * que varia é a altura do gráfico, que cresce conforme precisa de mais
 * fileiras para caber todo mundo na mesma largura.
 */
function rowLayout(total: number) {
    const usableWidth = 100 - GRID_MARGIN_X * 2;
    const columns = Math.max(1, Math.floor(usableWidth / GRID_CELL_SIZE));
    const rows = Math.ceil(total / columns);
    const viewBoxHeight = GRID_MARGIN_Y * 2 + rows * GRID_CELL_SIZE;

    const positions: { x: number; y: number }[] = [];
    let remaining = total;

    for (let row = 0; row < rows; row += 1) {
        const rowCount = Math.min(columns, remaining);
        // Fileira incompleta (a última) fica centralizada, não grudada à
        // esquerda — mais parecido com uma bancada de verdade.
        const offsetX = ((columns - rowCount) * GRID_CELL_SIZE) / 2;

        for (let col = 0; col < rowCount; col += 1) {
            positions.push({
                x: GRID_MARGIN_X + offsetX + GRID_CELL_SIZE * (col + 0.5),
                y: GRID_MARGIN_Y + GRID_CELL_SIZE * (row + 0.5),
            });
        }

        remaining -= rowCount;
    }

    return { positions, dotRadius: FIXED_DOT_RADIUS, viewBoxHeight };
}

/**
 * Semicírculo da casa. Em bancada estadual (Assembleia), mostra só as
 * cadeiras do estado do gabinete coloridas por partido, com o restante da
 * casa em cinza. Em bancada nacional (Senado, Deputado Federal — quando
 * `home_seats` vem preenchido), mostra o Brasil inteiro colorido por
 * partido, com um contorno nos pontos do estado do gabinete. Cores vêm de
 * `PartidoCor`.
 */
export function SeatHemicycle({
    parties,
    chamberTotal,
    uf,
    othersLabel,
    className,
}: {
    /** Cadeiras eleitas, por partido — do estado do gabinete (Assembleia) ou
     * do Brasil inteiro com `home_seats` (Senado, Deputado Federal). */
    parties: SeatParty[];
    /** Tamanho da casa inteira; o restante fica em cinza. */
    chamberTotal?: number | null;
    uf?: string;
    /** Legenda do cinza (cadeiras não contempladas em `parties`). Padrão:
     * "Ainda não decididas" (nacional) ou "Demais estados" (estadual). O
     * Senado renova só 1/3 ou 2/3 por eleição — o resto não é "ainda
     * contando", é mandato em curso, e precisa de um rótulo próprio. */
    othersLabel?: string;
    className?: string;
}) {
    const nationwide = parties.some((p) => p.home_seats !== undefined);
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

    const useRowLayout = total > FIXED_DOT_MAX_SEATS;
    const { positions, dotRadius, viewBoxHeight } = useRowLayout
        ? rowLayout(total)
        : seatLayout(total);
    const colors = [
        ...parties.flatMap((p) =>
            Array.from({ length: p.seats }, () => p.color ?? FALLBACK_COLOR),
        ),
        ...Array.from({ length: others }, () => GRAY),
    ];
    // No modo nacional, as primeiras `home_seats` cadeiras de cada partido
    // (dentro do segmento já colorido por partido) levam um contorno — não
    // representam candidatos específicos, só a contagem do estado.
    const highlighted = nationwide
        ? [
              ...parties.flatMap((p) => [
                  ...Array.from({ length: p.home_seats ?? 0 }, () => true),
                  ...Array.from(
                      { length: p.seats - (p.home_seats ?? 0) },
                      () => false,
                  ),
              ]),
              ...Array.from({ length: others }, () => false),
          ]
        : [];

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            {uf && (
                <p className="text-center text-xs text-muted-foreground">
                    {nationwide
                        ? `Cadeiras do Brasil inteiro; as de ${uf} têm contorno destacado.`
                        : `Cadeiras de ${uf} em destaque; o restante da casa em cinza.`}
                </p>
            )}
            <svg
                viewBox={`0 0 100 ${viewBoxHeight}`}
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
                        style={{
                            fill: colors[i],
                            stroke: highlighted[i]
                                ? 'var(--foreground)'
                                : 'none',
                            strokeWidth: highlighted[i] ? 0.3 : 0,
                        }}
                    />
                ))}
            </svg>
            <ul className="divide-y text-sm">
                {others > 0 && (
                    <li className="flex items-center justify-between gap-2 py-2">
                        <span className="flex min-w-0 items-center gap-2">
                            <span
                                className="size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: GRAY }}
                                aria-hidden="true"
                            />
                            <span className="truncate font-medium text-muted-foreground">
                                {othersLabel ??
                                    (nationwide
                                        ? 'Ainda não decididas'
                                        : 'Demais estados')}
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
                        className="flex items-center justify-between gap-2 py-2"
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
                            {nationwide && item.home_seats ? (
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    · {item.home_seats} em {uf}
                                </span>
                            ) : null}
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
