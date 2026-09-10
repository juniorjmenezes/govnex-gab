import { useEffect, useState } from 'react';
import AlertError from '@/components/alert-error';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AltArrowLeftIcon,
    AltArrowRightIcon,
    FeedIcon,
} from '@/components/icons';
import { OfficeBadge } from '@/components/politics/office-badge';
import { PartyBadge } from '@/components/politics/party-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { CandidateNews, PoliticalCandidate } from '@/types/politics';

type Page = {
    data: CandidateNews[];
    current_page: number;
    last_page: number;
    total: number;
};

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('pt-BR', {
              dateStyle: 'short',
              timeStyle: 'short',
          }).format(new Date(value))
        : null;

/** "#NE" é o marcador de nulo do TSE, não um status — não vai para a tela. */
const meaningful = (value: string | null): string | null =>
    value && value.trim().toUpperCase() !== '#NE' ? value : null;

function CandidateSummary({ candidate }: { candidate: PoliticalCandidate }) {
    const rows = [
        candidate.nome_urna && candidate.nome_urna !== candidate.nome
            ? { label: 'Nome completo', value: candidate.nome }
            : null,
        candidate.numero ? { label: 'Número', value: candidate.numero } : null,
        candidate.uf ? { label: 'UF', value: candidate.uf } : null,
        meaningful(candidate.situacao)
            ? { label: 'Situação', value: meaningful(candidate.situacao) }
            : null,
        meaningful(candidate.situacao_detalhada)
            ? {
                  label: 'Detalhe',
                  value: meaningful(candidate.situacao_detalhada),
              }
            : null,
    ].filter((row) => row !== null);

    return (
        <div className="space-y-4 sm:border-r sm:pr-6">
            <div className="flex flex-wrap items-center gap-2">
                <OfficeBadge office={candidate.cargo} />
                {candidate.partido_sigla && (
                    <PartyBadge
                        party={candidate.partido_sigla}
                        color={candidate.party_color}
                    />
                )}
            </div>

            {rows.length > 0 && (
                <dl className="space-y-3">
                    {rows.map((row) => (
                        <div key={row.label}>
                            <dt className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {row.label}
                            </dt>
                            <dd className="mt-0.5 text-sm">{row.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

export function CandidateNewsDialog({
    candidate,
    open,
    onOpenChange,
}: {
    candidate: PoliticalCandidate | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const tenantUrl = useTenantUrl();
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Page | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const candidateId = candidate?.id ?? null;

    useEffect(() => {
        if (!open || candidateId === null) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setError(null);

            try {
                const response = await fetch(
                    tenantUrl(
                        `/painel-politico/candidatos/${candidateId}/noticias?page=${page}`,
                    ),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );

                if (!response.ok) {
                    throw new Error('Não foi possível carregar as notícias.');
                }

                setResult((await response.json()) as Page);
            } catch (requestError) {
                if ((requestError as Error).name !== 'AbortError') {
                    setError('Não foi possível carregar as notícias.');
                }
            } finally {
                setLoading(false);
            }
        }, 0);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, candidateId, page, tenantUrl]);

    const news = result?.data ?? [];
    const lastPage = result?.last_page ?? 1;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {candidate?.nome_urna || candidate?.nome || ''}
                    </DialogTitle>
                    <DialogDescription>
                        {result
                            ? `${result.total} notícia(s) dos portais cadastrados`
                            : 'Notícias dos portais cadastrados'}
                    </DialogDescription>
                </DialogHeader>

                {error && (
                    <AlertError errors={[error]} title="Erro ao carregar" />
                )}

                <div className="grid min-h-0 flex-1 gap-6 sm:grid-cols-[16rem_minmax(0,1fr)]">
                    {candidate && <CandidateSummary candidate={candidate} />}

                    <div className="min-h-0 overflow-y-auto">
                        {loading && news.length === 0 ? (
                            <div className="grid h-full place-items-center sm:min-h-[min(24rem,50vh)]">
                                <Spinner />
                            </div>
                        ) : news.length === 0 ? (
                            <div className="grid h-full place-items-center sm:min-h-[min(24rem,50vh)]">
                                <EmptyState
                                    icon={FeedIcon}
                                    title="Nenhuma notícia encontrada"
                                    description="Ainda não há publicação dos portais cadastrados citando este candidato."
                                />
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {news.map((item) => {
                                    const published = formatDateTime(
                                        item.published_at,
                                    );

                                    return (
                                        <li key={item.id}>
                                            <a
                                                href={item.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex gap-3 p-4 transition-colors hover:bg-accent/60"
                                            >
                                                {item.image_url && (
                                                    <img
                                                        src={item.image_url}
                                                        alt=""
                                                        loading="lazy"
                                                        className="hidden size-16 shrink-0 rounded-sm object-cover sm:block"
                                                    />
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-xs whitespace-nowrap text-muted-foreground">
                                                        {[
                                                            item.source,
                                                            published,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </p>
                                                    <p className="mt-1 text-sm font-medium">
                                                        {item.title}
                                                    </p>
                                                    {item.summary && (
                                                        <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                                            {item.summary}
                                                        </p>
                                                    )}
                                                </div>
                                            </a>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>

                {lastPage > 1 && (
                    <div className="flex items-center justify-between gap-3 border-t pt-4">
                        <span className="text-xs text-muted-foreground">
                            Página {result?.current_page ?? page} de {lastPage}
                        </span>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={page <= 1 || loading}
                                onClick={() => setPage((value) => value - 1)}
                            >
                                <AltArrowLeftIcon />
                                Anterior
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={page >= lastPage || loading}
                                onClick={() => setPage((value) => value + 1)}
                            >
                                Próximo
                                <AltArrowRightIcon />
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
