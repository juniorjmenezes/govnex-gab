import { Head, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/feedback/empty-state';
import { AltArrowDownIcon, CalendarMarkIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { ElectionClock } from '@/components/politics/election-clock';
import { ElectionFavoritesCard } from '@/components/politics/election-favorites-card';
import { ElectionResultCard } from '@/components/politics/election-result-card';
import { SeatHemicycle } from '@/components/politics/seat-hemicycle';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { SectionCard } from '@/components/ui/section-card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
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

    // Composição das casas começa recolhida: decide devagar e não é o que se
    // acompanha primeiro. Sempre a composição real (UF/Brasil ou Câmara
    // Municipal) — o seletor de escopo não afeta quem tem cadeira.
    const [compositionOpen, setCompositionOpen] = useState(false);
    const seatResults = (liveResults ?? []).filter(
        (result) => result.seats !== null,
    );
    const electedSeats = seatResults.reduce(
        (total, result) =>
            total +
            (result.seats ?? []).reduce((sum, party) => sum + party.seats, 0),
        0,
    );
    const seatParties = new Set(
        seatResults.flatMap((result) =>
            (result.seats ?? []).map((party) => party.party),
        ),
    ).size;

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
                            <AppSelect
                                value={round.date}
                                onValueChange={(value) =>
                                    router.get(
                                        window.location.pathname,
                                        { data: value },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        },
                                    )
                                }
                                options={rounds.map((item) => ({
                                    value: item.date,
                                    label: item.label,
                                }))}
                                aria-label="Selecionar turno"
                                className="min-w-60"
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
                    <Collapsible
                        open={compositionOpen}
                        onOpenChange={setCompositionOpen}
                    >
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-between"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <span>Composição das casas</span>
                                    <span className="truncate text-muted-foreground">
                                        · {electedSeats} cadeiras eleitas ·{' '}
                                        {seatParties} partidos
                                    </span>
                                </span>
                                <AltArrowDownIcon
                                    aria-hidden="true"
                                    className={cn(
                                        'transition-transform',
                                        compositionOpen && 'rotate-180',
                                    )}
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="pt-4">
                            <section
                                aria-label="Composição das casas"
                                className="grid gap-4 lg:grid-cols-3"
                            >
                                {seatResults.map((result) => (
                                    <SectionCard
                                        key={result.cargo}
                                        title={`Composição · ${result.label}`}
                                        contentClassName="p-4"
                                    >
                                        <SeatHemicycle
                                            parties={result.seats ?? []}
                                            chamberTotal={result.chamber_total}
                                            // A Câmara Municipal é só da
                                            // cidade: não há cadeiras de outra
                                            // UF para destacar.
                                            uf={
                                                isMunicipalElection
                                                    ? undefined
                                                    : uf
                                            }
                                            othersLabel={
                                                result.cargo === 'senador'
                                                    ? 'Senadores em exercício'
                                                    : undefined
                                            }
                                        />
                                    </SectionCard>
                                ))}
                            </section>
                        </CollapsibleContent>
                    </Collapsible>
                )}
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
