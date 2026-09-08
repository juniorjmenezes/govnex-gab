import { Head, router } from '@inertiajs/react';
import { HeartIcon as HeartBoldIcon } from '@solar-icons/react/bold';
import {
    Buildings2Icon,
    CalendarMarkIcon,
    ChartIcon,
    CheckCircleIcon,
    CloseIcon,
    DatabaseIcon,
    HeartIcon,
    HeartIcon as HeartOutlineIcon,
    InfoCircleIcon,
    MagnifierIcon,
    MapPointIcon,
    MedalRibbonIcon,
    SquareArrowRightUpIcon,
    UsersGroupRoundedIcon,
    VerifiedCheckIcon,
} from '@solar-icons/react/outline';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PaginationLinks } from '@/components/common/pagination-links';
import { StatCard } from '@/components/common/stat-card';
import { EmptyState } from '@/components/feedback/empty-state';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { CandidateNewsDialog } from '@/components/politics/candidate-news-dialog';
import { OfficeBadge } from '@/components/politics/office-badge';
import { PartyBadge } from '@/components/politics/party-badge';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type {
    PoliticalCandidate,
    PoliticalPanelProps,
    PoliticalPoll,
    PoliticalPollOffice,
    PoliticalPolls,
    TseSyncSummary,
} from '@/types';

const numberFormatter = new Intl.NumberFormat('pt-BR');
const percentFormatter = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const dateFormatter = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' });
const dateTimeFormatter = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

/** Identifica a linha de votos em branco/nulo publicada por alguns
 * institutos como se fosse mais um "candidato" no resultado — deve sempre
 * aparecer por último na lista, independente do percentual. */
function isInvalidVoteLabel(name: string): boolean {
    const normalized = name.toUpperCase().trim();

    return (
        normalized === 'NÃO VÁLIDO' ||
        normalized === 'BRANCO/NULO' ||
        normalized === 'BRANCOS E NULOS' ||
        normalized === 'BRANCOS/NULOS'
    );
}

/**
 * "#NE" é o marcador de nulo do próprio TSE ("não existe"), não um status de
 * candidatura — exibi-lo só ocupa a linha com uma sigla sem significado para
 * quem lê. O dado bruto continua salvo como veio da fonte.
 */
function displaySituacao(situacao: string | null): string | null {
    return situacao === null || situacao.trim().toUpperCase() === '#NE'
        ? null
        : situacao;
}

function Countdown({
    target,
    serverNow,
}: {
    target: string;
    serverNow: string;
}) {
    const initialRemaining = useMemo(
        () =>
            Math.max(
                0,
                new Date(target).getTime() - new Date(serverNow).getTime(),
            ),
        [serverNow, target],
    );
    const [remaining, setRemaining] = useState(initialRemaining);

    useEffect(() => {
        const startedAt = Date.now();
        const interval = window.setInterval(() => {
            setRemaining(
                Math.max(0, initialRemaining - (Date.now() - startedAt)),
            );
        }, 1000);

        return () => window.clearInterval(interval);
    }, [initialRemaining]);

    const totalSeconds = Math.floor(remaining / 1000);
    const parts = [
        { label: 'dias', value: Math.floor(totalSeconds / 86400) },
        { label: 'horas', value: Math.floor((totalSeconds % 86400) / 3600) },
        { label: 'min', value: Math.floor((totalSeconds % 3600) / 60) },
        { label: 'seg', value: totalSeconds % 60 },
    ];

    return (
        <div className="grid grid-cols-4 gap-2" aria-live="off">
            {parts.map((part) => (
                <div
                    key={part.label}
                    className="rounded-xl bg-muted/60 px-3 py-2 text-center"
                >
                    <span className="block text-2xl font-bold tabular-nums">
                        {String(part.value).padStart(2, '0')}
                    </span>
                    <span className="mt-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {part.label}
                    </span>
                </div>
            ))}
        </div>
    );
}

function CandidateRow({
    candidate,
    canFavorite,
    onOpenNews,
}: {
    candidate: PoliticalCandidate;
    canFavorite: boolean;
    onOpenNews: (candidate: PoliticalCandidate) => void;
}) {
    // Só o favorito abre o modal, então só ele vira botão; os demais
    // seguem como div, sem alvo de clique nem parada de foco.
    const Body = candidate.is_favorite ? 'button' : 'div';

    const toggleFavorite = () => {
        const url = `/painel-politico/favoritos/${candidate.id}`;

        if (candidate.is_favorite) {
            router.delete(url, {
                preserveScroll: true,
                preserveState: true,
            });

            return;
        }

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    return (
        <div className="group flex items-center transition-colors hover:bg-muted/40">
            <button
                type="button"
                onClick={toggleFavorite}
                disabled={!canFavorite}
                aria-pressed={candidate.is_favorite}
                aria-label={
                    candidate.is_favorite
                        ? `Remover ${candidate.nome_urna} dos favoritos`
                        : `Adicionar ${candidate.nome_urna} aos favoritos`
                }
                title={
                    canFavorite
                        ? undefined
                        : 'Somente o vereador pode alterar os favoritos'
                }
                className={cn(
                    'shrink-0 py-3 pr-3 pl-5 text-muted-foreground/50 transition-colors hover:text-amber-500 focus-visible:text-amber-500 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50',
                    candidate.is_favorite && 'text-amber-500',
                )}
            >
                {candidate.is_favorite ? (
                    <HeartBoldIcon className="size-4" />
                ) : (
                    <HeartOutlineIcon className="size-4" />
                )}
            </button>
            <Body
                {...(candidate.is_favorite
                    ? {
                          type: 'button' as const,
                          onClick: () => onOpenNews(candidate),
                          'aria-label': `Ver notícias de ${candidate.nome_urna || candidate.nome}`,
                      }
                    : {})}
                className={cn(
                    'flex min-w-0 flex-1 items-center gap-3 py-3 pr-5 text-left',
                    candidate.is_favorite && 'cursor-pointer',
                )}
            >
                <div className="shrink-0">
                    <OfficeBadge office={candidate.cargo} />
                </div>
                <span className="min-w-0 flex-1 truncate text-sm font-medium">
                    {candidate.nome_urna || candidate.nome}
                </span>
                {candidate.nome_urna &&
                    candidate.nome_urna !== candidate.nome && (
                        <span className="hidden shrink-0 truncate text-xs text-muted-foreground sm:inline">
                            {candidate.nome}
                        </span>
                    )}
                <div className="hidden shrink-0 sm:block">
                    {candidate.partido_sigla ? (
                        <PartyBadge
                            party={candidate.partido_sigla}
                            color={candidate.party_color}
                        />
                    ) : (
                        <span className="text-xs whitespace-nowrap text-muted-foreground">
                            Sem partido
                        </span>
                    )}
                </div>
                {candidate.numero && (
                    <span className="shrink-0 font-heading text-sm font-semibold tabular-nums">
                        {candidate.numero}
                    </span>
                )}
                {displaySituacao(candidate.situacao) && (
                    <div className="hidden shrink-0 sm:block">
                        <Badge variant="outline">
                            {displaySituacao(candidate.situacao)}
                        </Badge>
                    </div>
                )}
            </Body>
        </div>
    );
}

/**
 * Nem toda fonte publica o detalhamento por candidato de cada pesquisa
 * individual — quando não publica, só a média consolidada tem números. Sem
 * isso, abrir a corrida na visão "Pesquisa individual" (o padrão) mostra
 * uma lista vazia mesmo com dados reais disponíveis. Detecta esse caso e
 * parte direto para a média, que é onde os números realmente estão.
 */
function officeDefaultMode(office: PoliticalPollOffice): 'poll' | 'average' {
    const latestPoll = office.polls[0];
    const latestHasNoBreakdown = !latestPoll || latestPoll.results.length === 0;

    return office.has_aggregate && latestHasNoBreakdown ? 'average' : 'poll';
}

function PollsSection({
    polls,
    sync,
    serverNow,
}: {
    polls: PoliticalPolls;
    sync: TseSyncSummary | null;
    serverNow: string;
}) {
    const [officeSlug, setOfficeSlug] = useState<
        'presidente' | 'governador' | 'senador' | 'prefeito'
    >('presidente');
    const [mode, setMode] = useState<'poll' | 'average'>(() => {
        const initialOffice =
            polls.offices.find((item) => item.slug === 'presidente') ??
            polls.offices[0];

        return initialOffice ? officeDefaultMode(initialOffice) : 'poll';
    });
    const [selectedPollId, setSelectedPollId] = useState<number | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [showInvalidVotes, setShowInvalidVotes] = useState(false);
    const [showBelowThreshold, setShowBelowThreshold] = useState(false);
    const activeOffice =
        polls.offices.find((item) => item.slug === officeSlug) ??
        polls.offices[0];

    if (!activeOffice) {
        return null;
    }

    const selectedPoll =
        activeOffice.polls.find((poll) => poll.id === selectedPollId) ??
        activeOffice.polls[0];
    const results = (
        mode === 'average'
            ? activeOffice.averages
            : (selectedPoll?.results ?? [])
    )
        .filter(
            (result) => showInvalidVotes || !isInvalidVoteLabel(result.name),
        )
        .filter((result) => showBelowThreshold || result.percentage >= 1)
        .toSorted(
            (a, b) =>
                Number(isInvalidVoteLabel(a.name)) -
                Number(isInvalidVoteLabel(b.name)),
        );
    const aggregateInfo = activeOffice.averages[0];
    const isOutdated = selectedPoll
        ? new Date(serverNow).getTime() -
              new Date(`${selectedPoll.publication_date}T12:00:00`).getTime() >
          60 * 86400000
        : false;

    const pollLabel = (poll: PoliticalPoll) =>
        `${poll.institute} · ${dateFormatter.format(new Date(`${poll.publication_date}T12:00:00`))}`;

    return (
        <section id="pesquisas" aria-labelledby="polls-title">
            <div className="mb-8 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <h2
                        id="polls-title"
                        className="text-xs font-semibold tracking-wide text-foreground uppercase"
                    >
                        Pesquisas eleitorais
                    </h2>
                    <span
                        className="text-muted-foreground/60"
                        aria-hidden="true"
                    >
                        &middot;
                    </span>
                    <p className="text-xs text-muted-foreground">
                        {polls.election_type === 'municipal'
                            ? `Prefeito em ${polls.municipality}/${polls.state}.`
                            : `Presidente em âmbito nacional; governador e Senado em ${polls.state}.`}{' '}
                        Os favoritos não interferem nos dados.
                    </p>
                </div>
                <a
                    href={polls.source_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                >
                    Fonte: {polls.source}
                    <SquareArrowRightUpIcon
                        className="size-3"
                        aria-hidden="true"
                    />
                </a>
            </div>

            <Surface className="overflow-hidden">
                <div className="flex flex-col gap-4 border-b p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex flex-wrap gap-2">
                        {polls.offices.map((item) => (
                            <Button
                                key={item.slug}
                                type="button"
                                size="sm"
                                variant={
                                    item.slug === activeOffice.slug
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => {
                                    setOfficeSlug(item.slug);
                                    setSelectedPollId(null);
                                    setMode(officeDefaultMode(item));
                                }}
                            >
                                {item.label}
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={mode === 'poll' ? 'secondary' : 'ghost'}
                            onClick={() => setMode('poll')}
                        >
                            Pesquisa individual
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={mode === 'average' ? 'secondary' : 'ghost'}
                            disabled={!activeOffice.has_aggregate}
                            onClick={() => setMode('average')}
                            title={
                                activeOffice.has_aggregate
                                    ? undefined
                                    : 'A média exige ao menos duas pesquisas'
                            }
                        >
                            Média
                        </Button>
                    </div>
                </div>

                {activeOffice.polls.length === 0 ? (
                    <EmptyState
                        icon={ChartIcon}
                        title={`Nenhuma pesquisa para ${activeOffice.label}`}
                        description={
                            sync?.status === 'falhou'
                                ? 'A última sincronização falhou. Tente novamente pela administração do gabinete.'
                                : 'Ainda não há levantamento para esta UF ou os dados ainda não foram sincronizados.'
                        }
                    />
                ) : (
                    <div className="grid lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <div className="p-5 sm:p-6">
                            {mode === 'poll' && selectedPoll && (
                                <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                    <div className="min-w-0 flex-1">
                                        <p className="mb-2 text-xs font-medium text-muted-foreground">
                                            Levantamento
                                        </p>
                                        <AppSelect
                                            value={selectedPoll.id.toString()}
                                            onValueChange={(value) =>
                                                setSelectedPollId(Number(value))
                                            }
                                            options={activeOffice.polls.map(
                                                (poll) => ({
                                                    value: poll.id.toString(),
                                                    label: pollLabel(poll),
                                                }),
                                            )}
                                            aria-label="Selecionar pesquisa"
                                            className="w-full sm:max-w-md"
                                        />
                                    </div>
                                    <label className="flex shrink-0 items-center gap-2 text-xs font-medium text-muted-foreground">
                                        <Switch
                                            size="sm"
                                            checked={showInvalidVotes}
                                            onCheckedChange={
                                                setShowInvalidVotes
                                            }
                                        />
                                        Mostrar não válidos
                                    </label>
                                    <label className="flex shrink-0 items-center gap-2 text-xs font-medium text-muted-foreground">
                                        <Switch
                                            size="sm"
                                            checked={showBelowThreshold}
                                            onCheckedChange={
                                                setShowBelowThreshold
                                            }
                                        />
                                        Exibir intenções abaixo de 1%
                                    </label>
                                    {isOutdated && (
                                        <Badge variant="outline">
                                            Pesquisa desatualizada
                                        </Badge>
                                    )}
                                </div>
                            )}

                            {mode === 'average' && aggregateInfo && (
                                <div className="mb-6">
                                    <p className="font-medium">
                                        {aggregateInfo.source === 'electiolab'
                                            ? 'Média ponderada do ElectioLab'
                                            : 'Média calculada pelo GOVNEX GAB'}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {numberFormatter.format(
                                            aggregateInfo.polls_included,
                                        )}{' '}
                                        pesquisas ·{' '}
                                        {numberFormatter.format(
                                            aggregateInfo.total_sample_size,
                                        )}{' '}
                                        entrevistas acumuladas
                                    </p>
                                </div>
                            )}

                            <p className="mb-4 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Abrangência: {activeOffice.scope_label}
                            </p>

                            {results.length === 0 ? (
                                <EmptyState
                                    icon={ChartIcon}
                                    title="Sem detalhamento por candidato"
                                    description={
                                        mode === 'poll'
                                            ? 'Esta pesquisa não trouxe o resultado por candidato — só a média consolidada tem números.'
                                            : 'Ainda não há média calculada para esta corrida.'
                                    }
                                    action={
                                        mode === 'poll' &&
                                        activeOffice.has_aggregate ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() =>
                                                    setMode('average')
                                                }
                                            >
                                                Ver média
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : (
                                <div className="rounded-xl border border-border p-4">
                                    <div className="divide-y divide-dashed divide-border">
                                        {results.map((result) => (
                                            <div
                                                key={
                                                    result.external_candidate_id
                                                }
                                                className="py-3 first:pt-0 last:pb-0"
                                            >
                                                <div className="mb-2 flex items-center justify-between gap-3">
                                                    <div className="flex min-w-0 items-center gap-2">
                                                        <span className="truncate text-sm font-medium">
                                                            {result.name}
                                                        </span>
                                                        {result.party && (
                                                            <PartyBadge
                                                                party={
                                                                    result.party
                                                                }
                                                                color={
                                                                    result.party_color
                                                                }
                                                            />
                                                        )}
                                                        {result.is_favorite && (
                                                            <HeartBoldIcon
                                                                className="size-3.5 text-amber-500"
                                                                aria-label="Favorito"
                                                            />
                                                        )}
                                                    </div>
                                                    <span className="shrink-0 font-heading text-base font-semibold tabular-nums">
                                                        {percentFormatter.format(
                                                            result.percentage,
                                                        )}
                                                        %
                                                    </span>
                                                </div>
                                                <Progress
                                                    className={
                                                        result.is_favorite
                                                            ? 'bg-amber-500/20'
                                                            : undefined
                                                    }
                                                    indicatorClassName={
                                                        result.is_favorite
                                                            ? 'bg-amber-500'
                                                            : undefined
                                                    }
                                                    value={Math.max(
                                                        0,
                                                        Math.min(
                                                            100,
                                                            result.percentage,
                                                        ),
                                                    )}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {activeOffice.senate_notice && (
                                <div className="mt-6 flex gap-3 rounded-xl bg-muted/60 p-4 text-xs text-muted-foreground">
                                    <InfoCircleIcon className="size-4 shrink-0 text-primary" />
                                    <p>{activeOffice.senate_notice}</p>
                                </div>
                            )}
                        </div>

                        <aside className="border-t bg-muted/25 p-5 text-xs lg:border-t-0 lg:border-l">
                            {mode === 'poll' && selectedPoll ? (
                                <PollMetadata poll={selectedPoll} />
                            ) : (
                                <div className="space-y-3 text-muted-foreground">
                                    <p className="font-medium text-foreground">
                                        Sobre esta visualização
                                    </p>
                                    <p>
                                        {aggregateInfo?.source === 'electiolab'
                                            ? 'A ponderação é calculada pelo ElectioLab.'
                                            : 'A ponderação é calculada pelo GOVNEX GAB a partir do histórico de pesquisas sincronizado.'}{' '}
                                        Os favoritos recebem apenas destaque
                                        visual e não alteram a média.
                                    </p>
                                </div>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-5 w-full"
                                onClick={() => setDetailsOpen(true)}
                            >
                                Ver todas as pesquisas
                            </Button>
                        </aside>
                    </div>
                )}
            </Surface>

            <Sheet open={detailsOpen} onOpenChange={setDetailsOpen}>
                <SheetContent className="overflow-y-auto sm:max-w-xl">
                    <SheetHeader className="border-b">
                        <SheetTitle>
                            Pesquisas para {activeOffice.label}
                        </SheetTitle>
                        <SheetDescription>
                            Histórico disponível para a UF do gabinete.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="space-y-4 p-6">
                        {activeOffice.polls.map((poll) => (
                            <article
                                key={poll.id}
                                className="rounded-xl border p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {poll.institute}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Publicada em{' '}
                                            {dateFormatter.format(
                                                new Date(
                                                    `${poll.publication_date}T12:00:00`,
                                                ),
                                            )}
                                        </p>
                                    </div>
                                    {poll.poll_type && (
                                        <Badge variant="outline">
                                            {poll.poll_type}
                                        </Badge>
                                    )}
                                </div>
                                <div className="mt-4 grid grid-cols-2 gap-3 text-xs">
                                    {poll.results.slice(0, 6).map((result) => (
                                        <div
                                            key={result.external_candidate_id}
                                            className="flex justify-between gap-2"
                                        >
                                            <span className="truncate">
                                                {result.name}
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {percentFormatter.format(
                                                    result.percentage,
                                                )}
                                                %
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </article>
                        ))}
                    </div>
                </SheetContent>
            </Sheet>
        </section>
    );
}

function PollMetadata({ poll }: { poll: PoliticalPoll }) {
    const collectionPeriod = poll.fieldwork_start
        ? `${dateFormatter.format(new Date(`${poll.fieldwork_start}T12:00:00`))}${
              poll.fieldwork_end && poll.fieldwork_end !== poll.fieldwork_start
                  ? ` a ${dateFormatter.format(new Date(`${poll.fieldwork_end}T12:00:00`))}`
                  : ''
          }`
        : 'Não informado';

    return (
        <div className="space-y-4">
            <div>
                <p className="font-medium text-foreground">{poll.institute}</p>
                <p className="mt-1 text-muted-foreground">
                    Publicada em{' '}
                    {dateFormatter.format(
                        new Date(`${poll.publication_date}T12:00:00`),
                    )}
                </p>
            </div>
            <dl className="space-y-3 text-muted-foreground">
                <div>
                    <dt>Período de coleta</dt>
                    <dd className="mt-0.5 font-medium text-foreground">
                        {collectionPeriod}
                    </dd>
                </div>
                <div>
                    <dt>Amostra</dt>
                    <dd className="mt-0.5 font-medium text-foreground">
                        {poll.sample_size
                            ? `${numberFormatter.format(poll.sample_size)} entrevistas`
                            : 'Não informada'}
                    </dd>
                </div>
                <div>
                    <dt>Margem de erro</dt>
                    <dd className="mt-0.5 font-medium text-foreground">
                        {poll.margin_of_error !== null
                            ? `± ${percentFormatter.format(poll.margin_of_error)} p.p.`
                            : 'Não informada'}
                    </dd>
                </div>
                <div>
                    <dt>Método</dt>
                    <dd className="mt-0.5 font-medium text-foreground capitalize">
                        {poll.methodology ?? 'Não informado'}
                    </dd>
                </div>
            </dl>
        </div>
    );
}

function syncDescription(sync: TseSyncSummary | null): string {
    if (!sync) {
        return 'Ainda não sincronizado';
    }

    if (sync.status === 'falhou') {
        return 'Última sincronização falhou';
    }

    if (sync.status === 'processando') {
        return 'Sincronização em andamento';
    }

    return sync.completed_at
        ? `Atualizado em ${dateTimeFormatter.format(new Date(sync.completed_at))}`
        : `${numberFormatter.format(sync.processed)} registros processados`;
}

/**
 * Situação de totalização do TSE abreviada para caber na linha do card, com o
 * significado disponível no title do <abbr>. A base traz quatro valores:
 * ELEITO POR QP, ELEITO POR MÉDIA, SUPLENTE e NÃO ELEITO — os dois últimos já
 * são curtos e ficam como estão. Qualquer outro valor que o TSE venha a enviar
 * passa direto, sem abreviação.
 */
const resultStatusAbbreviations: Record<
    string,
    { short: string; full: string }
> = {
    'ELEITO POR MÉDIA': { short: 'EPM', full: 'Eleito por média' },
    'ELEITO POR QP': {
        short: 'EQP',
        full: 'Eleito por quociente partidário',
    },
};

function resultStatusNode(status: string | null): ReactNode {
    if (!status) {
        return null;
    }

    const abbreviation = resultStatusAbbreviations[status.trim().toUpperCase()];

    if (!abbreviation) {
        return status;
    }

    return (
        <abbr
            title={abbreviation.full}
            className="cursor-help underline decoration-dotted underline-offset-2"
        >
            {abbreviation.short}
        </abbr>
    );
}

function holderVoteDescription(stats: PoliticalPanelProps['stats']): ReactNode {
    if (stats.holder_votes !== null) {
        const parts: ReactNode[] = [
            stats.holder_candidate_name,
            stats.holder_configured_number
                ? `nº ${stats.holder_configured_number}`
                : null,
            stats.holder_candidate_party,
            resultStatusNode(stats.holder_result_status),
        ].filter(Boolean);

        return parts.map((part, index) => (
            <Fragment key={index}>
                {index > 0 ? ' · ' : null}
                {part}
            </Fragment>
        ));
    }

    if (!stats.holder_configured_number) {
        return 'Informe o número eleitoral na administração do gabinete';
    }

    if (stats.holder_candidate_name) {
        return `${stats.holder_candidate_name} · aguardando sincronização da votação`;
    }

    return `Número ${stats.holder_configured_number} ainda não localizado no TSE`;
}

export default function PoliticalPanel({
    elections,
    selectedElectionId,
    candidates,
    filters,
    options,
    stats,
    municipality,
    countdown,
    serverNow,
    canFavorite,
    polls,
    sync,
}: PoliticalPanelProps) {
    const [query, setQuery] = useState(filters.q);
    const [office, setOffice] = useState(filters.cargo);
    const [party, setParty] = useState(filters.partido);
    const [favoritesOnly, setFavoritesOnly] = useState(filters.favoritos);
    const [newsCandidate, setNewsCandidate] =
        useState<PoliticalCandidate | null>(null);

    const navigate = (values: Record<string, string | number | boolean>) => {
        router.get(
            '/painel-politico',
            {
                ...(selectedElectionId && {
                    eleicao_id: selectedElectionId,
                }),
                ...(query && { q: query }),
                ...(office && { cargo: office }),
                ...(party && { partido: party }),
                ...(favoritesOnly && { favoritos: true }),
                ...values,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => navigate({}), 400);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, office, party]);

    const hasFilters = Boolean(query || office || party);

    const clear = () => {
        setQuery('');
        setOffice('');
        setParty('');
        setFavoritesOnly(false);
        router.get(
            '/painel-politico',
            selectedElectionId ? { eleicao_id: selectedElectionId } : {},
            { replace: true },
        );
    };

    return (
        <>
            <Head title="Painel político" />
            <PageContainer>
                <PageHeader
                    title="Painel político"
                    description={`Cenário eleitoral de ${municipality.name}/${municipality.state}, com dados oficiais e informações do gabinete separadas.`}
                    actions={
                        <AppSelect
                            value={selectedElectionId?.toString() ?? ''}
                            onValueChange={(value) =>
                                navigate({ eleicao_id: value })
                            }
                            options={elections.map((election) => ({
                                value: election.id.toString(),
                                label: election.name,
                            }))}
                            aria-label="Selecionar eleição"
                            className="min-w-60"
                        />
                    }
                />

                {!municipality.mapped && (
                    <div
                        role="status"
                        className="flex gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/8 p-4 text-sm"
                    >
                        <MapPointIcon className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-400" />
                        <div>
                            <p className="font-medium">
                                Município ainda não vinculado ao cadastro do TSE
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                Execute a sincronização do eleitorado para
                                localizar o código oficial e carregar a
                                quantidade de eleitores aptos.
                            </p>
                        </div>
                    </div>
                )}

                <section
                    aria-label="Indicadores eleitorais"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6"
                >
                    <StatCard
                        icon={VerifiedCheckIcon}
                        title="Eleitorado apto oficial"
                        value={
                            stats.official_eligible === null
                                ? '—'
                                : numberFormatter.format(
                                      stats.official_eligible,
                                  )
                        }
                        description={
                            stats.official_reference
                                ? `TSE · referência ${dateFormatter.format(new Date(`${stats.official_reference}T12:00:00`))}`
                                : 'Aguardando sincronização do TSE'
                        }
                    />
                    <StatCard
                        icon={CheckCircleIcon}
                        title="Votaram na última eleição"
                        value={
                            stats.last_turnout === null
                                ? '—'
                                : numberFormatter.format(stats.last_turnout)
                        }
                        description={
                            stats.last_turnout_percentage !== null
                                ? `${percentFormatter.format(stats.last_turnout_percentage)}% dos aptos · ${stats.last_election_name} · ${stats.last_election_round}º turno`
                                : 'Aguardando sincronização do comparecimento'
                        }
                    />
                    <StatCard
                        icon={MedalRibbonIcon}
                        title="Votos do titular do gabinete"
                        value={
                            stats.holder_votes === null
                                ? '—'
                                : numberFormatter.format(stats.holder_votes)
                        }
                        description={holderVoteDescription(stats)}
                    />
                    <StatCard
                        icon={UsersGroupRoundedIcon}
                        title="Eleitores no gabinete"
                        value={numberFormatter.format(stats.internal_voters)}
                        description="Cidadãos marcados como eleitores na base interna"
                    />
                    <StatCard
                        icon={Buildings2Icon}
                        title="Cobertura da base"
                        value={
                            stats.coverage_percentage === null
                                ? '—'
                                : `${percentFormatter.format(stats.coverage_percentage)}%`
                        }
                        description="Base interna em relação ao eleitorado oficial"
                    />
                    <StatCard
                        icon={HeartIcon}
                        title="Candidatos favoritos"
                        value={numberFormatter.format(stats.favorites)}
                        description="Seleções privadas deste gabinete"
                    />
                </section>

                {countdown && (
                    <Surface
                        as="section"
                        className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6"
                    >
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 text-primary">
                                <CalendarMarkIcon
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                <span className="text-xs font-semibold tracking-wide uppercase">
                                    Próxima eleição
                                </span>
                            </div>
                            <h2 className="mt-1 font-heading text-base font-semibold">
                                {countdown.label}
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                1º turno em{' '}
                                {dateFormatter.format(
                                    new Date(`${countdown.date}T12:00:00`),
                                )}
                            </p>
                        </div>
                        <div className="w-full shrink-0 sm:w-auto sm:max-w-sm">
                            <Countdown
                                key={countdown.target}
                                target={countdown.target}
                                serverNow={serverNow}
                            />
                        </div>
                    </Surface>
                )}

                <section>
                    <div className="mb-8 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Candidatos
                            </h2>
                            <span
                                className="text-muted-foreground/60"
                                aria-hidden="true"
                            >
                                &middot;
                            </span>
                            <p className="text-xs text-muted-foreground">
                                {numberFormatter.format(candidates.total)} nomes
                                compatíveis com a eleição e o território.
                            </p>
                        </div>
                        {!canFavorite && (
                            <p className="text-xs text-muted-foreground">
                                Somente o vereador pode alterar favoritos.
                            </p>
                        )}
                    </div>

                    <form
                        onSubmit={(event) => event.preventDefault()}
                        className={cn(
                            surfaceClasses,
                            'mb-4 flex flex-wrap items-center gap-3 p-4',
                        )}
                    >
                        <div className="relative min-w-56 flex-1">
                            <MagnifierIcon className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                className="pl-9"
                                placeholder="Nome, número ou partido"
                                aria-label="Buscar candidatos"
                            />
                        </div>
                        <AppSelect
                            value={office}
                            onValueChange={setOffice}
                            emptyLabel="Todos os cargos"
                            options={options.offices.map((value) => ({
                                value,
                                label: value,
                            }))}
                            aria-label="Filtrar por cargo"
                            className="w-52"
                        />
                        <AppSelect
                            value={party}
                            onValueChange={setParty}
                            emptyLabel="Todos os partidos"
                            options={options.parties.map((value) => ({
                                value,
                                label: value,
                            }))}
                            aria-label="Filtrar por partido"
                            className="w-52"
                        />
                        <Button
                            type="button"
                            variant={favoritesOnly ? 'default' : 'outline'}
                            onClick={() => {
                                const next = !favoritesOnly;
                                setFavoritesOnly(next);
                                navigate({ favoritos: next });
                            }}
                            aria-pressed={favoritesOnly}
                        >
                            <HeartIcon
                                className={
                                    favoritesOnly ? 'fill-current' : undefined
                                }
                            />
                            Favoritos
                        </Button>
                        {hasFilters && (
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={clear}
                                aria-label="Limpar filtros"
                            >
                                <CloseIcon />
                            </Button>
                        )}
                    </form>

                    <Surface as="section" className="overflow-hidden">
                        {candidates.data.length === 0 ? (
                            <EmptyState
                                icon={VerifiedCheckIcon}
                                title="Nenhum candidato encontrado"
                                description={
                                    sync.candidates
                                        ? 'Ajuste os filtros ou aguarde a publicação de novos registros pelo TSE.'
                                        : 'A lista será preenchida após a primeira sincronização de candidatos com o TSE.'
                                }
                            />
                        ) : (
                            <>
                                <div className="divide-y">
                                    {candidates.data.map((candidate) => (
                                        <CandidateRow
                                            key={candidate.id}
                                            candidate={candidate}
                                            canFavorite={canFavorite}
                                            onOpenNews={setNewsCandidate}
                                        />
                                    ))}
                                </div>
                                <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                                    Exibindo {candidates.from}–{candidates.to}{' '}
                                    de {candidates.total} candidato(s)
                                </div>
                                <PaginationLinks links={candidates.links} />
                            </>
                        )}
                    </Surface>
                </section>

                {polls.offices.length > 0 && (
                    <PollsSection
                        polls={polls}
                        sync={sync.polls}
                        serverNow={serverNow}
                    />
                )}

                <section className="grid gap-4 rounded-2xl bg-muted/35 p-5 text-xs text-muted-foreground ring-1 ring-foreground/8 sm:grid-cols-3">
                    <div className="flex gap-3">
                        <DatabaseIcon className="size-4 shrink-0" />
                        <div>
                            <p className="font-medium text-foreground">
                                Eleitorado oficial
                            </p>
                            <p className="mt-1">
                                {syncDescription(sync.electorate)}
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-3">
                        <DatabaseIcon className="size-4 shrink-0" />
                        <div>
                            <p className="font-medium text-foreground">
                                Candidaturas oficiais
                            </p>
                            <p className="mt-1">
                                {syncDescription(sync.candidates)}
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-3">
                        <DatabaseIcon className="size-4 shrink-0" />
                        <div>
                            <p className="font-medium text-foreground">
                                Pesquisas eleitorais · PollingData
                            </p>
                            <p className="mt-1">
                                {syncDescription(sync.polls)}
                            </p>
                        </div>
                    </div>
                </section>
            </PageContainer>
            <CandidateNewsDialog
                key={newsCandidate?.id ?? 'none'}
                candidate={newsCandidate}
                open={newsCandidate !== null}
                onOpenChange={(open) => !open && setNewsCandidate(null)}
            />
        </>
    );
}

PoliticalPanel.layout = {
    breadcrumbs: [
        {
            title: 'Painel político',
            href: '/painel-politico',
        },
    ],
};
