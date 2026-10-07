import { Head, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useEffect } from 'react';
import { EmptyState } from '@/components/feedback/empty-state';
import { CalendarMarkIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { ElectionClock } from '@/components/politics/election-clock';
import { ElectionFavoritesCard } from '@/components/politics/election-favorites-card';
import { ElectionResultCard } from '@/components/politics/election-result-card';
import { SeatHemicycle } from '@/components/politics/seat-hemicycle';
import { AppSelect } from '@/components/ui/app-select';
import { SectionCard } from '@/components/ui/section-card';
import type { ElectionTallyProps } from '@/types/politics';

/** Enquanto algum cargo ainda não chegou a 100% das seções (ou nem foi
 * publicado), vale a pena continuar buscando números novos sozinho. */
function isStillCounting(results: ElectionTallyProps['results']): boolean {
    return (
        results !== null &&
        results.some(
            (result) =>
                !result.available || (result.section_percent ?? 0) < 100,
        )
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
    useEffect(() => {
        if (!isStillCounting(results)) {
            return;
        }

        const { stop } = router.poll(90_000, {
            only: ['results', 'favorites'],
        });

        return stop;
    }, [results]);

    const generalSectionPercent =
        results?.find((result) => result.section_percent !== null)
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

                {round ? (
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
                ) : (
                    <EmptyState
                        icon={CalendarMarkIcon}
                        title="Nenhuma eleição cadastrada"
                        description="A apuração aparece aqui assim que uma eleição for cadastrada na sincronização política."
                    />
                )}

                {round && (
                    <section
                        aria-label="Apuração no município"
                        className="flex flex-col gap-4"
                    >
                        {municipal ? (
                            <SectionCard
                                title={`Meu município · ${municipal.name}`}
                                description={
                                    municipal.section_percent !== null
                                        ? `${municipal.section_percent}% das seções apuradas`
                                        : undefined
                                }
                                contentClassName="grid gap-4 p-4 lg:grid-cols-6"
                            >
                                {municipal.results.map((result) => (
                                    <ElectionResultCard
                                        key={result.cargo}
                                        result={result}
                                        className={
                                            [
                                                'presidente',
                                                'governador',
                                            ].includes(result.cargo)
                                                ? 'lg:col-span-3'
                                                : 'lg:col-span-2'
                                        }
                                    />
                                ))}
                            </SectionCard>
                        ) : (
                            <SectionCard title="Meu município">
                                <EmptyState
                                    size="compact"
                                    icon={CalendarMarkIcon}
                                    title="Município não vinculado"
                                    description="Vincule o município eleitoral do gabinete para ver a apuração da cidade."
                                />
                            </SectionCard>
                        )}
                    </section>
                )}

                {favorites && <ElectionFavoritesCard favorites={favorites} />}

                {results && (
                    <SectionCard
                        title="Apuração geral"
                        description={
                            generalSectionPercent !== null
                                ? `${generalSectionPercent}% das seções apuradas`
                                : undefined
                        }
                        contentClassName="grid gap-4 p-4 lg:grid-cols-6"
                    >
                        {results.map((result) => (
                            <ElectionResultCard
                                key={result.cargo}
                                result={result}
                                uf={uf}
                                className={
                                    ['presidente', 'governador'].includes(
                                        result.cargo,
                                    )
                                        ? 'lg:col-span-3'
                                        : 'lg:col-span-2'
                                }
                            />
                        ))}
                    </SectionCard>
                )}

                {results && (
                    <section
                        aria-label="Composição das casas"
                        className="grid gap-4 lg:grid-cols-3"
                    >
                        {results
                            .filter((result) => result.seats !== null)
                            .map((result) => (
                                <SectionCard
                                    key={result.cargo}
                                    title={`Composição · ${result.label}`}
                                    contentClassName="p-4"
                                >
                                    <SeatHemicycle
                                        parties={result.seats ?? []}
                                        chamberTotal={result.chamber_total}
                                        uf={uf}
                                        othersLabel={
                                            result.cargo === 'senador'
                                                ? 'Senadores em exercício'
                                                : undefined
                                        }
                                    />
                                </SectionCard>
                            ))}
                    </section>
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
