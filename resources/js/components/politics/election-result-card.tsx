import { useState } from 'react';
import {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    CheckCircleIcon,
    ClockCircleIcon,
    MagnifierIcon,
} from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { SectionCard } from '@/components/ui/section-card';
import { cn } from '@/lib/utils';
import type { ElectionResult, ElectionResultCandidate } from '@/types/politics';

const integerFormatter = new Intl.NumberFormat('pt-BR');
const percentFormatter = new Intl.NumberFormat('pt-BR', {
    maximumFractionDigits: 1,
});

/** Eleito quando o TSE marca; "Não eleito" só depois da apuração concluída
 * (antes disso, ninguém está definitivamente fora). */
function ElectionBadge({
    candidate,
    finished,
}: {
    candidate: ElectionResultCandidate;
    finished: boolean;
}) {
    if (candidate.elected) {
        return <Badge variant="success">Eleito</Badge>;
    }

    if (finished) {
        return <Badge variant="outline">Não eleito</Badge>;
    }

    return null;
}

function CandidateRows({
    candidates,
    finished,
    flush = false,
}: {
    candidates: ElectionResultCandidate[];
    finished: boolean;
    /** Dentro do diálogo, o padding vem do corpo: a linha não repete o recuo. */
    flush?: boolean;
}) {
    return (
        <ul className="divide-y">
            {candidates.map((candidate) => (
                <li
                    key={candidate.number}
                    className={cn(
                        'flex items-center gap-3 py-2.5 text-sm',
                        flush ? '' : 'px-4',
                    )}
                >
                    <span className="w-10 shrink-0 font-mono text-xs text-muted-foreground tabular-nums">
                        {candidate.number}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="flex min-w-0 items-center gap-2 font-medium">
                            <span className="truncate">
                                {candidate.ballot_name}
                            </span>
                            <ElectionBadge
                                candidate={candidate}
                                finished={finished}
                            />
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {candidate.party}
                        </p>
                    </div>
                    <div className="shrink-0 text-right">
                        <p className="font-medium tabular-nums">
                            {integerFormatter.format(candidate.votes)}
                        </p>
                        <p className="text-xs text-muted-foreground tabular-nums">
                            {percentFormatter.format(candidate.vote_percent)}%
                        </p>
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function ElectionResultCard({
    result,
    className,
}: {
    result: ElectionResult;
    className?: string;
}) {
    const [showAll, setShowAll] = useState(false);
    const [query, setQuery] = useState('');
    const term = query.trim().toLowerCase();
    const filtered =
        term === ''
            ? result.all_candidates
            : result.all_candidates.filter((candidate) =>
                  [
                      candidate.number,
                      candidate.name,
                      candidate.ballot_name,
                      candidate.party,
                      candidate.coalition ?? '',
                  ]
                      .join(' ')
                      .toLowerCase()
                      .includes(term),
              );
    const sectionPercent = result.section_percent ?? 0;
    const hasMore = result.total_candidates > result.candidates.length;

    return (
        <>
            <SectionCard
                title={result.label}
                description={
                    result.available
                        ? `${percentFormatter.format(sectionPercent)}% das seções apuradas`
                        : undefined
                }
                actions={
                    result.goes_to_second_round ? (
                        <Badge variant="warning">Vai ao 2º turno</Badge>
                    ) : undefined
                }
                className={cn('min-w-0', className)}
                contentClassName="flex flex-col p-0"
            >
                {!result.available ? (
                    <EmptyState
                        size="compact"
                        icon={ClockCircleIcon}
                        title="Ainda sem dados do TSE"
                        description="O arquivo deste cargo ainda não foi publicado — tentamos de novo em instantes."
                    />
                ) : result.candidates.length === 0 ? (
                    <EmptyState
                        size="compact"
                        icon={ClockCircleIcon}
                        title="Sem candidatos apurados"
                        description="O TSE publicou o arquivo, mas ele ainda não lista candidatos para este cargo."
                    />
                ) : (
                    <>
                        <CandidateRows
                            candidates={result.candidates}
                            finished={result.finished}
                        />
                        {hasMore && (
                            <div className="mt-auto border-t px-4 py-2.5">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="w-full"
                                    onClick={() => setShowAll(true)}
                                >
                                    <CheckCircleIcon aria-hidden="true" />
                                    Ver todos os resultados (
                                    {result.total_candidates})
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </SectionCard>

            <Dialog open={showAll} onOpenChange={setShowAll}>
                <ScrollableDialogContent className="h-[calc(100svh-1rem)] sm:h-[min(42rem,90svh)] sm:max-w-2xl">
                    <ScrollableDialogHeader>
                        <DialogTitle>{result.label}</DialogTitle>
                        <DialogDescription>
                            {result.total_candidates} candidatos ·{' '}
                            {percentFormatter.format(sectionPercent)}% das
                            seções apuradas
                        </DialogDescription>
                    </ScrollableDialogHeader>
                    <ScrollableDialogBody>
                        <div className="relative mb-4">
                            <MagnifierIcon
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Buscar por nome, número, partido ou coligação"
                                aria-label="Buscar candidatos"
                                className="pl-9"
                            />
                        </div>
                        {filtered.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                Nenhum candidato encontrado.
                            </p>
                        ) : (
                            <CandidateRows
                                candidates={filtered}
                                finished={result.finished}
                                flush
                            />
                        )}
                    </ScrollableDialogBody>
                </ScrollableDialogContent>
            </Dialog>
        </>
    );
}
