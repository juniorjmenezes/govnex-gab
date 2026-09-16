import { Head, router } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PaginationLinks } from '@/components/common/pagination-links';
import { StatCard } from '@/components/common/stat-card';
import { TableGroupRow } from '@/components/common/table-group-row';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    Buildings2Icon,
    CalendarMarkIcon,
    ChartIcon,
    CheckCircleIcon,
    CloseIcon,
    DatabaseIcon,
    HeartIcon,
    HeartBoldIcon,
    HeartIcon as HeartOutlineIcon,
    InfoCircleIcon,
    MagnifierIcon,
    MapPointIcon,
    MedalRibbonIcon,
    SquareArrowRightUpIcon,
    UsersGroupRoundedIcon,
    VerifiedCheckIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { CandidateNewsDialog } from '@/components/politics/candidate-news-dialog';
import { OfficeBadge } from '@/components/politics/office-badge';
import { PartyBadge } from '@/components/politics/party-badge';
import { PollsHistoryDrawer } from '@/components/politics/polls-history-drawer';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { preservedListParams } from '@/lib/pagination';
import { isInvalidVoteLabel } from '@/lib/poll-results';
import { cn } from '@/lib/utils';
import type {
    PoliticalCandidate,
    PoliticalMunicipalElection,
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

/** Percentual sobre os eleitores aptos, na mesma linha do número. */
function ShareOfEligible({ percentage }: { percentage: number | null }) {
    if (percentage === null) {
        return null;
    }

    return (
        <span className="ml-1.5 text-xs font-normal text-muted-foreground">
            {percentFormatter.format(percentage)}%
            <span className="sr-only"> dos aptos</span>
        </span>
    );
}

/**
 * Apuração da eleição municipal escolhida no seletor. Aparece no lugar do
 * card de pesquisas: com o resultado publicado, a intenção de voto daquela
 * eleição não tem mais uso.
 */
function MunicipalElectionResult({
    summary,
}: {
    summary: PoliticalMunicipalElection | null;
}) {
    if (summary === null) {
        return null;
    }

    const { election, turnout, holder, mayor } = summary;

    return (
        <Surface as="section" className="overflow-hidden">
            <SurfaceHeader
                actions={
                    <p className="shrink-0 text-xs text-muted-foreground">
                        <span className="text-sm font-medium text-foreground tabular-nums">
                            {numberFormatter.format(summary.candidates)}
                        </span>{' '}
                        candidatos ·{' '}
                        <span className="text-sm font-medium text-foreground tabular-nums">
                            {numberFormatter.format(summary.seats)}
                        </span>{' '}
                        eleitos
                    </p>
                }
            >
                <SurfaceTitle>Resultado da eleição</SurfaceTitle>
                <SurfaceDescription>
                    {election.name} · 1º turno em{' '}
                    {dateFormatter.format(
                        new Date(`${election.date}T12:00:00`),
                    )}
                </SurfaceDescription>
            </SurfaceHeader>

            {turnout && (
                <dl className="grid grid-cols-2 gap-4 border-b p-4 sm:grid-cols-4">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Eleitores aptos
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums">
                            {numberFormatter.format(turnout.eligible)}
                        </dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Comparecimento
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums">
                            {numberFormatter.format(turnout.voted)}
                            <ShareOfEligible percentage={turnout.percentage} />
                        </dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Abstenções
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums">
                            {numberFormatter.format(turnout.abstentions)}
                            <ShareOfEligible
                                percentage={
                                    turnout.eligible > 0
                                        ? (turnout.abstentions /
                                              turnout.eligible) *
                                          100
                                        : null
                                }
                            />
                        </dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Cadeiras
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums">
                            {numberFormatter.format(summary.seats)}
                        </dd>
                    </div>
                </dl>
            )}

            {holder && (
                <div className="border-b p-4">
                    <p className="text-xs text-muted-foreground">
                        Titular do gabinete
                    </p>
                    <p className="mt-0.5 text-sm font-medium">
                        {holder.name}
                        {holder.party && ` · ${holder.party}`}
                        {holder.number && ` · ${holder.number}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {numberFormatter.format(holder.votes)} votos ·{' '}
                        {holder.position}º entre{' '}
                        {numberFormatter.format(summary.candidates)} candidatos
                        ·{' '}
                        {holder.elected
                            ? (displaySituacao(holder.result_status) ??
                              'Eleito')
                            : 'Não eleito'}
                    </p>
                </div>
            )}

            {summary.parties.length > 0 && (
                <div className="grid grid-cols-2 gap-3 border-b p-4 sm:grid-cols-3 lg:grid-cols-4">
                    {summary.parties.map((party) => (
                        <div
                            key={party.party}
                            className="rounded-md border border-l-2 p-3"
                            style={
                                party.color
                                    ? { borderLeftColor: party.color }
                                    : undefined
                            }
                        >
                            <p
                                className="text-xs font-medium"
                                style={
                                    party.color
                                        ? { color: party.color }
                                        : undefined
                                }
                            >
                                {party.party}
                            </p>
                            <p className="mt-1 font-mono text-lg leading-none font-medium tabular-nums">
                                {numberFormatter.format(party.seats)}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {party.seats === 1 ? 'cadeira' : 'cadeiras'} ·{' '}
                                {numberFormatter.format(party.votes)} votos
                            </p>
                        </div>
                    ))}
                </div>
            )}

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>#</TableHead>
                        <TableHead>Candidato</TableHead>
                        <TableHead>Partido</TableHead>
                        <TableHead>Votos</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {mayor && (
                        <>
                            <TableGroupRow
                                title="Prefeito"
                                subtitle={`Resultado do ${mayor.round}º turno`}
                                colSpan={4}
                            />
                            {mayor.candidates.map((candidate) => (
                                <TableRow
                                    key={`${candidate.position}-${candidate.name}`}
                                >
                                    <TableCell className="text-xs text-muted-foreground tabular-nums">
                                        {candidate.position}
                                    </TableCell>
                                    <TableCell>
                                        <p className="font-normal">
                                            {candidate.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {candidate.elected
                                                ? 'Eleito'
                                                : 'Não eleito'}
                                        </p>
                                    </TableCell>
                                    <TableCell>
                                        {candidate.party ? (
                                            <PartyBadge
                                                party={candidate.party}
                                                color={candidate.party_color}
                                            />
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm tabular-nums">
                                        {numberFormatter.format(
                                            candidate.votes,
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </>
                    )}

                    <TableGroupRow
                        title="Vereadores eleitos"
                        subtitle={`${numberFormatter.format(summary.seats)} de ${numberFormatter.format(summary.candidates)} candidatos`}
                        colSpan={4}
                    />
                    {summary.elected.map((candidate) => (
                        <TableRow
                            key={`${candidate.position}-${candidate.name}`}
                        >
                            <TableCell className="text-xs text-muted-foreground tabular-nums">
                                {candidate.position}
                            </TableCell>
                            <TableCell>
                                <p
                                    className={
                                        candidate.is_holder
                                            ? 'font-normal text-primary'
                                            : 'font-normal'
                                    }
                                >
                                    {candidate.name}
                                    {candidate.is_holder && (
                                        <span className="sr-only">
                                            , titular do gabinete
                                        </span>
                                    )}
                                </p>
                            </TableCell>
                            <TableCell>
                                {candidate.party ? (
                                    <PartyBadge
                                        party={candidate.party}
                                        color={candidate.party_color}
                                    />
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-sm tabular-nums">
                                {numberFormatter.format(candidate.votes)}
                            </TableCell>
                        </TableRow>
                    ))}
                    {summary.runners_up.length > 0 && (
                        <>
                            <TableGroupRow
                                title="Não eleitos mais votados"
                                subtitle="Quem ficou mais perto da cadeira"
                                colSpan={4}
                            />
                            {summary.runners_up.map((candidate) => (
                                <TableRow
                                    key={`${candidate.position}-${candidate.name}`}
                                >
                                    <TableCell className="text-xs text-muted-foreground tabular-nums">
                                        {candidate.position}
                                    </TableCell>
                                    <TableCell>
                                        <p
                                            className={
                                                candidate.is_holder
                                                    ? 'font-normal text-primary'
                                                    : 'font-normal'
                                            }
                                        >
                                            {candidate.name}
                                            {candidate.is_holder && (
                                                <span className="sr-only">
                                                    , titular do gabinete
                                                </span>
                                            )}
                                        </p>
                                    </TableCell>
                                    <TableCell>
                                        {candidate.party ? (
                                            <PartyBadge
                                                party={candidate.party}
                                                color={candidate.party_color}
                                            />
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm tabular-nums">
                                        {numberFormatter.format(
                                            candidate.votes,
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </>
                    )}
                </TableBody>
            </Table>
        </Surface>
    );
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
                    <span className="block font-mono text-2xl font-bold tabular-nums">
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
    const tenantUrl = useTenantUrl();
    const displayName = candidate.nome_urna || candidate.nome;

    const toggleFavorite = () => {
        const url = tenantUrl(`/painel-politico/favoritos/${candidate.id}`);

        if (candidate.is_holder) {
            return;
        }

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
        <TableRow>
            <TableCell className="w-10">
                <button
                    type="button"
                    onClick={toggleFavorite}
                    disabled={!canFavorite || candidate.is_holder}
                    aria-pressed={candidate.is_favorite}
                    aria-label={
                        candidate.is_holder
                            ? `${displayName} é o titular do gabinete e fica sempre nos favoritos`
                            : candidate.is_favorite
                              ? `Remover ${displayName} dos favoritos`
                              : `Adicionar ${displayName} aos favoritos`
                    }
                    title={
                        candidate.is_holder
                            ? 'O titular do gabinete fica sempre nos favoritos'
                            : canFavorite
                              ? undefined
                              : 'Somente o vereador pode alterar os favoritos'
                    }
                    className={cn(
                        'flex cursor-pointer text-muted-foreground/50 transition-colors hover:text-primary focus-visible:text-primary focus-visible:outline-none disabled:pointer-events-none',
                        candidate.is_favorite && 'text-primary',
                        !candidate.is_holder && 'disabled:opacity-50',
                    )}
                >
                    {candidate.is_favorite ? (
                        <HeartBoldIcon className="size-4" />
                    ) : (
                        <HeartOutlineIcon className="size-4" />
                    )}
                </button>
            </TableCell>
            <TableCell>
                {/* Só o favorito tem notícias coletadas, então só ele abre o
                    modal; os demais ficam como texto, sem parada de foco. */}
                {candidate.is_favorite ? (
                    <button
                        type="button"
                        onClick={() => onOpenNews(candidate)}
                        aria-label={`Ver notícias e informações de ${displayName}`}
                        className="cursor-pointer text-left hover:underline"
                    >
                        {displayName}
                    </button>
                ) : (
                    displayName
                )}
            </TableCell>
            <TableCell className="tabular-nums">
                {candidate.numero ?? '—'}
            </TableCell>
            <TableCell>
                {candidate.partido_sigla ? (
                    <PartyBadge
                        party={candidate.partido_sigla}
                        color={candidate.party_color}
                    />
                ) : (
                    <span className="whitespace-nowrap text-muted-foreground">
                        Sem partido
                    </span>
                )}
            </TableCell>
            <TableCell>
                <OfficeBadge office={candidate.cargo} />
            </TableCell>
            <TableCell className="text-muted-foreground">
                {candidate.nome}
            </TableCell>
            <TableCell>
                {displaySituacao(candidate.situacao) ? (
                    <Badge variant="outline">
                        {displaySituacao(candidate.situacao)}
                    </Badge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
        </TableRow>
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
        <>
            <Surface
                as="section"
                id="pesquisas"
                aria-labelledby="polls-title"
                className="overflow-hidden"
            >
                <SurfaceHeader
                    actions={
                        <a
                            href={polls.source_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                        >
                            Fonte: {polls.source}
                            <SquareArrowRightUpIcon
                                className="size-3"
                                aria-hidden="true"
                            />
                        </a>
                    }
                >
                    <SurfaceTitle id="polls-title">
                        Pesquisas eleitorais
                    </SurfaceTitle>
                    <SurfaceDescription>
                        {polls.election_type === 'municipal'
                            ? `Prefeito em ${polls.municipality}/${polls.state}`
                            : `Presidente, governador e Senado em ${polls.state}`}
                    </SurfaceDescription>
                </SurfaceHeader>
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
                                                                className="size-3.5 text-primary"
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
                                                    // O favorito usa a cor do
                                                    // gabinete (--primary, que
                                                    // o OfficeTheme troca).
                                                    className={
                                                        result.is_favorite
                                                            ? 'bg-primary/20 dark:bg-primary/20'
                                                            : undefined
                                                    }
                                                    indicatorClassName={
                                                        result.is_favorite
                                                            ? 'bg-primary dark:bg-primary'
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

            <PollsHistoryDrawer
                // O filtro de instituto recomeça ao trocar de cargo.
                key={activeOffice.slug}
                open={detailsOpen}
                onOpenChange={setDetailsOpen}
                office={activeOffice}
                selectedPollId={
                    mode === 'poll' ? (selectedPoll?.id ?? null) : null
                }
                onSelectPoll={(pollId) => {
                    setSelectedPollId(pollId);
                    setMode('poll');
                    setDetailsOpen(false);
                }}
            />
        </>
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
    municipalElection,
    municipality,
    countdown,
    serverNow,
    canFavorite,
    polls,
    sync,
}: PoliticalPanelProps) {
    const tenantUrl = useTenantUrl();
    const [query, setQuery] = useState(filters.q);
    const [office, setOffice] = useState(filters.cargo);
    const [party, setParty] = useState(filters.partido);
    const [favoritesOnly, setFavoritesOnly] = useState(filters.favoritos);
    const [newsCandidate, setNewsCandidate] =
        useState<PoliticalCandidate | null>(null);

    const navigate = (values: Record<string, string | number | boolean>) => {
        router.get(
            tenantUrl('/painel-politico'),
            {
                ...(selectedElectionId && {
                    eleicao_id: selectedElectionId,
                }),
                ...(query && { q: query }),
                ...(office && { cargo: office }),
                ...(party && { partido: party }),
                ...(favoritesOnly && { favoritos: true }),
                ...preservedListParams(),
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
            tenantUrl('/painel-politico'),
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
                    <Alert variant="warning">
                        <MapPointIcon />
                        <AlertTitle>
                            Município ainda não vinculado ao cadastro do TSE
                        </AlertTitle>
                        <AlertDescription>
                            Execute a sincronização do eleitorado para localizar
                            o código oficial e carregar a quantidade de
                            eleitores aptos.
                        </AlertDescription>
                    </Alert>
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
                        <div className="flex min-w-0 items-center gap-3">
                            <CalendarMarkIcon
                                className="size-5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                <h2 className="text-sm font-semibold">
                                    {countdown.label}
                                </h2>
                                <span
                                    className="hidden text-muted-foreground/60 sm:inline"
                                    aria-hidden="true"
                                >
                                    &middot;
                                </span>
                                <p className="basis-full text-xs text-muted-foreground sm:basis-auto">
                                    1º turno em{' '}
                                    {dateFormatter.format(
                                        new Date(`${countdown.date}T12:00:00`),
                                    )}
                                </p>
                            </div>
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

                <MunicipalElectionResult summary={municipalElection} />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader
                        help="Favorite um candidato pelo coração para acompanhá-lo. O nome dos favoritos fica clicável e abre as informações do candidato e as notícias relacionadas publicadas nos portais cadastrados."
                        actions={
                            !canFavorite && (
                                <p className="shrink-0 text-xs text-muted-foreground">
                                    Somente o vereador pode alterar favoritos.
                                </p>
                            )
                        }
                    >
                        <SurfaceTitle>Candidatos</SurfaceTitle>
                        <SurfaceDescription>
                            {numberFormatter.format(candidates.total)} nomes
                            compatíveis com a eleição e o território.
                        </SurfaceDescription>
                    </SurfaceHeader>

                    <form
                        onSubmit={(event) => event.preventDefault()}
                        className="flex flex-wrap items-center gap-3 border-b p-4"
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
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Favorito
                                            </span>
                                        </TableHead>
                                        <TableHead>Nome de urna</TableHead>
                                        <TableHead>Número</TableHead>
                                        <TableHead>Partido</TableHead>
                                        <TableHead>Cargo</TableHead>
                                        <TableHead>Nome completo</TableHead>
                                        <TableHead>Situação</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {candidates.data.map((candidate) => (
                                        <CandidateRow
                                            key={candidate.id}
                                            candidate={candidate}
                                            canFavorite={canFavorite}
                                            onOpenNews={setNewsCandidate}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                            <PaginationLinks
                                pagination={candidates}
                                label="candidato(s)"
                            />
                        </>
                    )}
                </Surface>

                {polls !== null && polls.offices.length > 0 && (
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
