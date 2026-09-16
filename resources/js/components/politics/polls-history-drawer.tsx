import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AltArrowDownIcon,
    AltArrowUpIcon,
    ChartIcon,
    CloseIcon,
    HeartBoldIcon,
    SquareArrowRightUpIcon,
} from '@/components/icons';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { isInvalidVoteLabel, sortPollResults } from '@/lib/poll-results';
import { cn } from '@/lib/utils';
import type {
    PoliticalPoll,
    PoliticalPollOffice,
    PoliticalPollResult,
} from '@/types';

const VISIBLE_RESULTS = 3;

const numberFormatter = new Intl.NumberFormat('pt-BR');
const percentFormatter = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const deltaFormatter = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});
const dateFormatter = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' });

const toDate = (value: string) => new Date(`${value}T12:00:00`);

/**
 * Histórico de pesquisas de uma corrida, no mesmo drawer lateral das
 * demandas: cabeçalho fixo, lista com rolagem própria e rodapé fixo. É uma
 * visão geral — cada pesquisa mostra só os primeiros colocados, com a
 * variação frente à anterior do mesmo instituto, e pode ser aberta no painel.
 */
export function PollsHistoryDrawer({
    open,
    onOpenChange,
    office,
    selectedPollId,
    onSelectPoll,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    office: PoliticalPollOffice;
    selectedPollId: number | null;
    onSelectPoll: (pollId: number) => void;
}) {
    const [institute, setInstitute] = useState('');
    const institutes = useMemo(
        () =>
            [...new Set(office.polls.map((poll) => poll.institute))].toSorted(
                (a, b) => a.localeCompare(b, 'pt-BR'),
            ),
        [office.polls],
    );
    const visiblePolls = institute
        ? office.polls.filter((poll) => poll.institute === institute)
        : office.polls;

    return (
        <Drawer open={open} onOpenChange={onOpenChange} swipeDirection="right">
            <DrawerContent side="right">
                <DrawerHeader className="shrink-0 flex-row items-start justify-between gap-3 border-b p-4">
                    <div className="min-w-0">
                        <DrawerTitle>Pesquisas para {office.label}</DrawerTitle>
                        <DrawerDescription className="mt-0.5">
                            {numberFormatter.format(office.polls.length)}{' '}
                            {office.polls.length === 1
                                ? 'pesquisa'
                                : 'pesquisas'}{' '}
                            · {office.scope_label}
                        </DrawerDescription>
                    </div>
                    <DrawerClose
                        render={<Button variant="ghost" size="icon-sm" />}
                        aria-label="Fechar"
                    >
                        <CloseIcon aria-hidden="true" />
                    </DrawerClose>
                </DrawerHeader>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {visiblePolls.length === 0 ? (
                        <EmptyState
                            icon={ChartIcon}
                            title="Nenhuma pesquisa"
                            description="Não há levantamentos para o filtro escolhido."
                        />
                    ) : (
                        <ol className="divide-y">
                            {visiblePolls.map((poll) => (
                                <li key={poll.id}>
                                    <PollHistoryItem
                                        poll={poll}
                                        previous={previousPollFrom(
                                            office.polls,
                                            poll,
                                        )}
                                        selected={poll.id === selectedPollId}
                                        onSelect={() => onSelectPoll(poll.id)}
                                    />
                                </li>
                            ))}
                        </ol>
                    )}
                </div>

                <div className="flex shrink-0 items-center justify-end gap-2 border-t p-4">
                    {institutes.length > 1 && (
                        <AppSelect
                            value={institute}
                            onValueChange={setInstitute}
                            options={institutes.map((item) => ({
                                value: item,
                                label: item,
                            }))}
                            emptyLabel="Todos os institutos"
                            aria-label="Filtrar por instituto"
                            className="min-w-0 flex-1"
                        />
                    )}
                    <Button
                        variant="ghost"
                        className="shrink-0"
                        onClick={() => onOpenChange(false)}
                    >
                        Fechar
                    </Button>
                </div>
            </DrawerContent>
        </Drawer>
    );
}

/** As pesquisas chegam da mais recente para a mais antiga. */
function previousPollFrom(
    polls: PoliticalPoll[],
    poll: PoliticalPoll,
): PoliticalPoll | null {
    const index = polls.indexOf(poll);

    return (
        polls
            .slice(index + 1)
            .find(
                (item) =>
                    item.institute === poll.institute &&
                    item.results.length > 0,
            ) ?? null
    );
}

const linkClasses =
    'inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground';

/**
 * Visão geral de uma pesquisa: instituto, data e os primeiros colocados. O
 * detalhamento completo fica no card principal ("Exibir no painel").
 */
function PollHistoryItem({
    poll,
    previous,
    selected,
    onSelect,
}: {
    poll: PoliticalPoll;
    previous: PoliticalPoll | null;
    selected: boolean;
    onSelect: () => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const results = sortPollResults(poll.results);
    const validResults = results.filter(
        (result) => !isInvalidVoteLabel(result.name),
    );
    const shown = expanded ? results : validResults.slice(0, VISIBLE_RESULTS);
    const hiddenCount = results.length - shown.length;
    const previousByCandidate = new Map(
        (previous?.results ?? []).map((result) => [
            result.external_candidate_id,
            result.percentage,
        ]),
    );

    const meta = [
        dateFormatter.format(toDate(poll.publication_date)),
        poll.sample_size &&
            `${numberFormatter.format(poll.sample_size)} entrevistas`,
        poll.margin_of_error !== null &&
            `±${deltaFormatter.format(poll.margin_of_error)} p.p.`,
    ].filter(Boolean);

    return (
        <article
            className="space-y-3 px-5 py-4"
            aria-current={selected ? 'true' : undefined}
        >
            <header className="flex items-baseline justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-medium">
                        {poll.institute}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {meta.join(' · ')}
                    </p>
                </div>
                {selected && (
                    <Badge variant="outline" className="shrink-0">
                        Em exibição
                    </Badge>
                )}
            </header>

            {shown.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    Sem resultado por candidato.
                </p>
            ) : (
                <ul className="space-y-1">
                    {shown.map((result) => (
                        <ResultRow
                            key={result.external_candidate_id}
                            result={result}
                            previous={previousByCandidate.get(
                                result.external_candidate_id,
                            )}
                        />
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                {(hiddenCount > 0 || expanded) && (
                    <button
                        type="button"
                        className={linkClasses}
                        aria-expanded={expanded}
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded ? (
                            <AltArrowUpIcon
                                className="size-3"
                                aria-hidden="true"
                            />
                        ) : (
                            <AltArrowDownIcon
                                className="size-3"
                                aria-hidden="true"
                            />
                        )}
                        {expanded
                            ? 'Mostrar menos'
                            : `Ver todos (${numberFormatter.format(results.length)})`}
                    </button>
                )}
                {!selected && results.length > 0 && (
                    <button
                        type="button"
                        className={linkClasses}
                        onClick={onSelect}
                    >
                        Exibir no painel
                    </button>
                )}
                <a
                    href={poll.source_url}
                    target="_blank"
                    rel="noreferrer"
                    className={linkClasses}
                >
                    Fonte
                    <SquareArrowRightUpIcon
                        className="size-3"
                        aria-hidden="true"
                    />
                </a>
            </div>
        </article>
    );
}

function ResultRow({
    result,
    previous,
}: {
    result: PoliticalPollResult;
    previous: number | undefined;
}) {
    const invalid = isInvalidVoteLabel(result.name);
    const delta = previous === undefined ? null : result.percentage - previous;

    return (
        <li className="flex items-center justify-between gap-3 text-sm">
            <span
                className={cn(
                    'flex min-w-0 items-center gap-1.5',
                    invalid && 'text-muted-foreground',
                )}
            >
                <span className="truncate">{result.name}</span>
                {result.party && (
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {result.party}
                    </span>
                )}
                {result.is_favorite && (
                    <HeartBoldIcon
                        className="size-3 shrink-0 text-primary"
                        aria-label="Favorito"
                    />
                )}
            </span>
            <span className="flex shrink-0 items-baseline gap-2 tabular-nums">
                {delta !== null && Math.abs(delta) >= 0.05 && (
                    <span
                        className="inline-flex items-center text-xs text-muted-foreground"
                        title="Variação frente à pesquisa anterior do mesmo instituto"
                    >
                        {delta > 0 ? (
                            <AltArrowUpIcon
                                className="size-3"
                                aria-hidden="true"
                            />
                        ) : (
                            <AltArrowDownIcon
                                className="size-3"
                                aria-hidden="true"
                            />
                        )}
                        <span className="sr-only">
                            {delta > 0 ? 'subiu' : 'caiu'}
                        </span>
                        {deltaFormatter.format(Math.abs(delta))}
                    </span>
                )}
                <span className="font-medium">
                    {percentFormatter.format(result.percentage)}%
                </span>
            </span>
        </li>
    );
}
