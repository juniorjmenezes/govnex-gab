import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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

/** Altura da barra, em px. */
const BAR_HEIGHT = 48;
/** Nenhuma fatia fica menor que isso, em % da barra — um partido com 1
 * cadeira na Câmara (513 no total) seria um traço de menos de 1px e
 * impossível de apontar o mouse; abaixo disso ela cresce até aqui e o
 * espaço sai proporcionalmente dos maiores (ver {@link barWidths}). */
const MIN_SLICE_PERCENT = 1.5;

interface PartyGroup {
    key: string;
    label: string;
    seats: number;
    homeSeats: number;
    color: string;
    /** Contorno: bancada do partido tem alguma cadeira do estado do gabinete. */
    highlighted: boolean;
}

/**
 * Largura de cada fatia da barra, em % — proporcional às cadeiras, mas sem
 * deixar nenhum partido pequeno sumir: toda fatia sai com pelo menos
 * {@link MIN_SLICE_PERCENT}, e a diferença sai proporcionalmente dos
 * partidos acima do mínimo (sem isso, a soma passaria de 100%).
 */
function barWidths(groups: PartyGroup[]) {
    const total = groups.reduce((sum, g) => sum + g.seats, 0);
    const raw = groups.map((g) => (g.seats / total) * 100);
    const floored = raw.map((percent) => percent < MIN_SLICE_PERCENT);
    const floorTotal = floored.filter(Boolean).length * MIN_SLICE_PERCENT;
    const flexTotal = groups
        .filter((_, i) => !floored[i])
        .reduce((sum, g) => sum + g.seats, 0);
    const remaining = 100 - floorTotal;

    return groups.map((group, i) => ({
        ...group,
        percent: floored[i]
            ? MIN_SLICE_PERCENT
            : (group.seats / flexTotal) * remaining,
    }));
}

/** Casa com `home_seats` nos partidos é bancada nacional (Senado, Deputado
 * Federal) — o Brasil inteiro, com a UF do gabinete em destaque; sem isso, é
 * só a UF (Assembleia) ou só o município (Câmara Municipal). */
export function isNationwideSeats(parties: SeatParty[]): boolean {
    return parties.some((p) => p.home_seats !== undefined);
}

/** Até esse tanto, a lista de partidos (na própria tela) mostra todo mundo;
 * acima disso, só os primeiros — o resto fica pro botão "ver todos" do
 * drawer que contém este gráfico. */
export const PARTY_LIST_LIMIT = 8;

/**
 * Uma linha de partido — cor, sigla, cadeiras no estado do gabinete (bancada
 * nacional) e total. Reaproveitada tanto na lista compacta deste componente
 * quanto na lista completa do drawer que o envolve (`SeatCompositionDrawer`).
 */
export function PartyRow({
    item,
    nationwide,
    uf,
}: {
    item: SeatParty;
    nationwide: boolean;
    uf?: string;
}) {
    return (
        <li className="flex items-center justify-between gap-2 py-2">
            <span className="flex min-w-0 items-center gap-2">
                <span
                    className="size-2.5 shrink-0 rounded-[2px]"
                    style={{ backgroundColor: item.color ?? FALLBACK_COLOR }}
                    aria-hidden="true"
                />
                <span className="truncate font-medium">{item.party}</span>
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
    );
}

/**
 * Composição da casa numa barra única, fatiada por partido (ver
 * {@link barWidths}) — cada fatia com tooltip (sigla e cadeiras) — mais a
 * lista compacta de partidos abaixo. Em bancada estadual (Assembleia), mostra
 * só as cadeiras do estado do gabinete coloridas por partido, com o restante
 * da casa em cinza. Em bancada nacional (Senado, Deputado Federal — quando
 * `home_seats` vem preenchido), mostra o Brasil inteiro colorido por partido,
 * com uma faixa de destaque na base das fatias do estado do gabinete. Cores
 * vêm de `PartyColor`.
 *
 * A lista completa (busca incluída) e o botão "ver todos" ficam no drawer
 * que envolve este componente (`SeatCompositionDrawer`), não aqui — o drawer
 * é o único nível de painel sobreposto, sem abrir outro por cima.
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
    const nationwide = isNationwideSeats(parties);
    const elected = parties.reduce((sum, p) => sum + p.seats, 0);
    const total = Math.max(elected, chamberTotal ?? elected);
    const others = total - elected;
    const shownParties = parties.slice(0, PARTY_LIST_LIMIT);

    if (total === 0) {
        return (
            <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                Nenhuma cadeira definida ainda.
            </p>
        );
    }

    const othersResolvedLabel =
        othersLabel ?? (nationwide ? 'Ainda não decididas' : 'Demais estados');
    const groups: PartyGroup[] = [
        ...parties
            .filter((p) => p.seats > 0)
            .map((p) => ({
                key: p.party,
                label: p.party,
                seats: p.seats,
                homeSeats: p.home_seats ?? 0,
                color: p.color ?? FALLBACK_COLOR,
                highlighted: nationwide && (p.home_seats ?? 0) > 0,
            })),
        ...(others > 0
            ? [
                  {
                      key: '__others__',
                      label: othersResolvedLabel,
                      seats: others,
                      homeSeats: 0,
                      color: GRAY,
                      highlighted: false,
                  },
              ]
            : []),
    ];
    const slices = barWidths(groups);

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            {uf && (
                <p className="text-center text-xs text-muted-foreground">
                    {nationwide
                        ? `Cadeiras do Brasil inteiro; as de ${uf} têm contorno destacado.`
                        : `Cadeiras de ${uf} em destaque; o restante da casa em cinza.`}
                </p>
            )}
            <div
                className="flex w-full overflow-hidden rounded-md"
                style={{ height: BAR_HEIGHT }}
                role="img"
                aria-label={parties
                    .map((p) => `${p.party}: ${p.seats}`)
                    .join(', ')}
            >
                {slices.map((slice, i) => (
                    <Tooltip key={slice.key}>
                        <TooltipTrigger asChild>
                            <div
                                tabIndex={0}
                                className="relative shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                style={{
                                    width: `${slice.percent}%`,
                                    backgroundColor: slice.color,
                                    borderRight:
                                        i < slices.length - 1
                                            ? '1px solid var(--background)'
                                            : undefined,
                                }}
                            >
                                {slice.highlighted && (
                                    <span
                                        className="absolute inset-x-0 bottom-0 h-1 bg-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent>
                            {slice.label}:{' '}
                            {slice.seats === 1
                                ? '1 cadeira'
                                : `${slice.seats} cadeiras`}
                            {slice.highlighted &&
                                ` · ${slice.homeSeats} em ${uf}`}
                        </TooltipContent>
                    </Tooltip>
                ))}
            </div>
            <ul className="divide-y text-sm">
                {others > 0 && (
                    <li className="flex items-center justify-between gap-2 py-2">
                        <span className="flex min-w-0 items-center gap-2">
                            <span
                                className="size-2.5 shrink-0 rounded-[2px]"
                                style={{ backgroundColor: GRAY }}
                                aria-hidden="true"
                            />
                            <span className="truncate font-medium text-muted-foreground">
                                {othersResolvedLabel}
                            </span>
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {others}
                        </span>
                    </li>
                )}
                {shownParties.map((item) => (
                    <PartyRow
                        key={item.party}
                        item={item}
                        nationwide={nationwide}
                        uf={uf}
                    />
                ))}
            </ul>
        </div>
    );
}
