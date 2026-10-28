import { Head, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AltArrowLeftIcon,
    AltArrowRightIcon,
    CalendarMarkIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { ElectionClock } from '@/components/politics/election-clock';
import { ElectionFavoritesCard } from '@/components/politics/election-favorites-card';
import { ElectionResultCard } from '@/components/politics/election-result-card';
import { SeatCompositionDrawer } from '@/components/politics/seat-composition-drawer';
import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/ui/section-card';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { ElectionResult, ElectionTallyProps } from '@/types/politics';

/** Enquanto algum cargo ainda não fechou (100% das seções no feed do TSE,
 * ou situação totalizada na votação municipal) ou nem foi publicado, vale a
 * pena continuar buscando números novos sozinho. */
function isStillCounting(
    results: ElectionResult[] | null | undefined,
): boolean {
    return (
        !!results &&
        results.some((result) => !result.available || !result.finished)
    );
}

/** Cargos majoritários ocupam meia linha; os de cadeira, um terço. */
const HALF_WIDTH_CARGOS: ElectionResult['cargo'][] = [
    'presidente',
    'governador',
    'prefeito',
    'vereador',
];

/**
 * Navegador de turno: setas anterior/próximo em volta do rótulo (que também
 * é um `Select` simples, pra pular direto sem clicar várias vezes). Ao
 * contrário de um combobox com busca, não cresce em complexidade conforme
 * mais eleições são cadastradas — só mais um passo nas setas.
 */
function RoundStepper({
    rounds,
    current,
}: {
    rounds: { date: string; label: string }[];
    current: string;
}) {
    // O backend manda do mais novo pro mais antigo, agrupado por eleição;
    // aqui vale a ordem cronológica, pra "anterior"/"próximo" andarem na
    // direção certa no tempo.
    const sorted = [...rounds].sort((a, b) => a.date.localeCompare(b.date));
    const index = sorted.findIndex((item) => item.date === current);
    const previous = index > 0 ? sorted[index - 1] : null;
    const next =
        index >= 0 && index < sorted.length - 1 ? sorted[index + 1] : null;

    const goTo = (date: string) =>
        router.get(
            window.location.pathname,
            { data: date },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <div className="flex items-center gap-1">
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Turno anterior"
                disabled={!previous}
                onClick={() => previous && goTo(previous.date)}
            >
                <AltArrowLeftIcon aria-hidden="true" />
            </Button>
            <Select value={current} onValueChange={goTo}>
                <SelectTrigger
                    className="min-w-48 justify-center"
                    aria-label="Selecionar turno"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent align="center">
                    <SelectGroup>
                        {sorted.map((item) => (
                            <SelectItem key={item.date} value={item.date}>
                                {item.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Próximo turno"
                disabled={!next}
                onClick={() => next && goTo(next.date)}
            >
                <AltArrowRightIcon aria-hidden="true" />
            </Button>
        </div>
    );
}

export default function ElectionTally({
    round,
    rounds,
    results,
    municipal,
    favorites,
    serverNow,
    uf,
}: ElectionTallyProps) {
    // Eleição municipal: a disputa é a do próprio município, então ela é o
    // que se acompanha (e o que vai na composição da Câmara). Em eleição
    // geral, a cidade é um escopo alternativo do mesmo card — ver `scope`.
    const isMunicipalElection = round?.type === 'municipal';
    const liveResults = isMunicipalElection
        ? (municipal?.results ?? null)
        : results;

    useEffect(() => {
        if (!isStillCounting(liveResults)) {
            return;
        }

        const { stop } = router.poll(90_000, {
            only: isMunicipalElection
                ? ['municipal', 'favorites']
                : ['results', 'municipal', 'favorites'],
        });

        return stop;
    }, [liveResults, isMunicipalElection]);

    // Fora da eleição municipal, "Geral" (UF/Brasil) e "Minha cidade" mostram
    // os mesmos cargos em escopos diferentes, na mesma seção — sem município
    // vinculado, não há o que alternar.
    const [scope, setScope] = useState<'geral' | 'cidade'>('geral');
    const canScopeToCity = !isMunicipalElection && municipal !== null;
    const shownResults = isMunicipalElection
        ? (municipal?.results ?? null)
        : canScopeToCity && scope === 'cidade'
          ? municipal!.results
          : results;

    // Composição de cada casa abre sob pedido num drawer, uma por vez — o
    // seletor de escopo acima não afeta quem tem cadeira, é sempre a
    // composição real (UF/Brasil ou Câmara Municipal).
    const [openSeatResult, setOpenSeatResult] = useState<ElectionResult | null>(
        null,
    );
    const seatResults = (liveResults ?? []).filter(
        (result) => result.seats !== null,
    );

    const shownSectionPercent =
        shownResults?.find((result) => result.section_percent !== null)
            ?.section_percent ?? null;

    const description = !round
        ? undefined
        : round.is_today
          ? `Dia de votação · ${round.label}`
          : `Próximo turno: ${round.label} · ${format(parseISO(round.date), "d 'de' MMMM", { locale: ptBR })}`;

    return (
        <>
            <Head title="Apuração" />
            <PageContainer>
                <PageHeader
                    title="Apuração"
                    description={description}
                    actions={
                        rounds.length > 1 && round ? (
                            <RoundStepper
                                rounds={rounds}
                                current={round.date}
                            />
                        ) : undefined
                    }
                />

                {!round && (
                    <EmptyState
                        icon={CalendarMarkIcon}
                        title="Nenhuma eleição cadastrada"
                        description="A apuração aparece aqui assim que uma eleição for cadastrada na sincronização política."
                    />
                )}

                {/* Fora do dia da votação o relógio não diz nada que o
                    PageHeader já não diga. */}
                {round?.is_today && (
                    <SectionCard
                        title="Horário de votação"
                        description="08h às 17h, sem intervalo"
                        className="items-center"
                        contentClassName="flex items-center justify-center py-8"
                    >
                        <ElectionClock
                            label={round.label}
                            windowStart={round.window_start}
                            windowEnd={round.window_end}
                            serverNow={serverNow}
                        />
                    </SectionCard>
                )}

                {favorites && <ElectionFavoritesCard favorites={favorites} />}

                {shownResults ? (
                    <SectionCard
                        title={
                            isMunicipalElection
                                ? `Meu município · ${municipal?.name}`
                                : scope === 'cidade'
                                  ? `Minha cidade · ${municipal?.name}`
                                  : 'Apuração geral'
                        }
                        description={
                            shownSectionPercent !== null
                                ? `${shownSectionPercent}% das seções apuradas`
                                : undefined
                        }
                        actions={
                            canScopeToCity ? (
                                <ToggleGroup
                                    type="single"
                                    value={scope}
                                    onValueChange={(value) => {
                                        if (
                                            value === 'geral' ||
                                            value === 'cidade'
                                        ) {
                                            setScope(value);
                                        }
                                    }}
                                    variant="outline"
                                    spacing={0}
                                    aria-label="Escopo da apuração"
                                >
                                    <ToggleGroupItem value="geral">
                                        Geral
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="cidade">
                                        {municipal?.name}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            ) : undefined
                        }
                        contentClassName="grid gap-4 p-4 lg:grid-cols-6"
                    >
                        {shownResults.map((result) => (
                            <ElectionResultCard
                                key={result.cargo}
                                result={result}
                                uf={
                                    isMunicipalElection || scope === 'cidade'
                                        ? undefined
                                        : uf
                                }
                                className={
                                    HALF_WIDTH_CARGOS.includes(result.cargo)
                                        ? 'lg:col-span-3'
                                        : 'lg:col-span-2'
                                }
                            />
                        ))}
                    </SectionCard>
                ) : (
                    isMunicipalElection && (
                        <SectionCard title="Meu município">
                            <EmptyState
                                size="compact"
                                icon={CalendarMarkIcon}
                                title="Município não vinculado"
                                description="Vincule o município eleitoral do gabinete para ver a apuração da cidade."
                            />
                        </SectionCard>
                    )
                )}

                {seatResults.length > 0 && (
                    <SectionCard
                        title="Composição das casas"
                        contentClassName="divide-y p-0"
                    >
                        {seatResults.map((result) => {
                            const seats = result.seats ?? [];
                            const houseSeats = seats.reduce(
                                (sum, party) => sum + party.seats,
                                0,
                            );

                            return (
                                <button
                                    key={result.cargo}
                                    type="button"
                                    className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm transition-colors hover:bg-muted/50"
                                    onClick={() => setOpenSeatResult(result)}
                                >
                                    <span className="min-w-0 truncate font-medium">
                                        {result.label}
                                    </span>
                                    <span className="flex shrink-0 items-center gap-2 text-muted-foreground">
                                        {houseSeats} cadeiras · {seats.length}{' '}
                                        partidos
                                        <AltArrowRightIcon
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </span>
                                </button>
                            );
                        })}
                    </SectionCard>
                )}

                <SeatCompositionDrawer
                    result={openSeatResult}
                    open={openSeatResult !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setOpenSeatResult(null);
                        }
                    }}
                    uf={uf}
                    municipal={isMunicipalElection}
                />
            </PageContainer>
        </>
    );
}

ElectionTally.layout = {
    breadcrumbs: [
        { title: 'Painel político', href: '/painel-politico' },
        { title: 'Apuração', href: '/painel-politico/apuracao' },
    ],
};
