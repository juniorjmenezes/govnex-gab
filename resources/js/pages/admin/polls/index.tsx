import { Head, router } from '@inertiajs/react';
import {
    AddIcon,
    CloseIcon,
    MagnifierIcon,
    PenIcon,
    PresentationGraphIcon,
    TrashBinTrashIcon,
} from '@solar-icons/react/outline';
import { useEffect, useRef, useState } from 'react';
import { PollCandidateRows } from '@/components/admin/poll-candidate-rows';
import { PaginationLinks } from '@/components/common/pagination-links';
import {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogFooter,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
import { TableActionButton } from '@/components/common/table-action-button';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { usePollCandidateOptions } from '@/hooks/use-poll-candidate-options';
import {
    CARGO_OPTIONS,
    CENARIO_LABELS,
    emptyCandidateRow,
} from '@/lib/poll-curation';
import type { CandidateRowInput } from '@/lib/poll-curation';
import { cn } from '@/lib/utils';
import type { PollCurationPesquisa, PollCurationProps } from '@/types';

const cargoLabels: Record<string, string> = Object.fromEntries(
    CARGO_OPTIONS.map((option) => [option.value, option.label]),
);

export default function PollCuration({
    elections,
    filters,
    pesquisas,
}: PollCurationProps) {
    const [eleicaoId, setEleicaoId] = useState(
        filters.eleicao_id ? String(filters.eleicao_id) : '',
    );
    const [cargo, setCargo] = useState(filters.cargo);
    const [uf, setUf] = useState(filters.uf);
    const [q, setQ] = useState(filters.q);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                '/admin/pesquisas-eleitorais',
                { eleicao_id: eleicaoId, cargo, uf, q },
                { preserveState: true, replace: true },
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [eleicaoId, cargo, uf, q]);

    const hasFilters = Boolean(eleicaoId || cargo || uf || q);
    const clearFilters = () => {
        setEleicaoId('');
        setCargo('');
        setUf('');
        setQ('');
        router.get('/admin/pesquisas-eleitorais', {}, { replace: true });
    };

    const [resultsTarget, setResultsTarget] =
        useState<PollCurationPesquisa | null>(null);
    const [deleteTarget, setDeleteTarget] =
        useState<PollCurationPesquisa | null>(null);

    return (
        <>
            <Head title="Pesquisas eleitorais" />
            <PageContainer>
                <PageHeader
                    title="Pesquisas eleitorais"
                    description="O PollingData cobre presidente, mas não governador, senador ou prefeito. Aqui é possível registrar e corrigir pesquisas à mão, direto do PDF ou matéria original."
                    actions={
                        <Button
                            onClick={() =>
                                router.get('/admin/pesquisas-eleitorais/nova')
                            }
                        >
                            <AddIcon className="size-4" aria-hidden="true" />
                            Nova pesquisa
                        </Button>
                    }
                />

                <form
                    onSubmit={(event) => event.preventDefault()}
                    className={cn(
                        surfaceClasses,
                        'flex flex-wrap items-center gap-3 p-4',
                    )}
                >
                    <AppSelect
                        value={eleicaoId}
                        onValueChange={setEleicaoId}
                        emptyLabel="Todas as eleições"
                        options={elections.map((election) => ({
                            value: String(election.id),
                            label: `${election.name} (${election.year})`,
                        }))}
                        aria-label="Filtrar por eleição"
                        className="w-56"
                    />
                    <AppSelect
                        value={cargo}
                        onValueChange={setCargo}
                        emptyLabel="Todos os cargos"
                        options={CARGO_OPTIONS}
                        aria-label="Filtrar por cargo"
                        className="w-44"
                    />
                    <Input
                        value={uf}
                        onChange={(event) =>
                            setUf(event.target.value.toUpperCase())
                        }
                        maxLength={2}
                        placeholder="UF"
                        className="w-20 uppercase"
                    />
                    <div className="relative min-w-56 flex-1">
                        <MagnifierIcon
                            className="absolute top-2.5 left-3 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Instituto ou município"
                            className="pl-9"
                        />
                    </div>
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={clearFilters}
                            aria-label="Limpar filtros"
                        >
                            <CloseIcon />
                        </Button>
                    )}
                </form>

                <Surface as="section" className="overflow-hidden">
                    <div className="divide-y">
                        {pesquisas.data.map((pesquisa) => (
                            <PollRow
                                key={pesquisa.id}
                                pesquisa={pesquisa}
                                onEditResults={() => setResultsTarget(pesquisa)}
                                onDelete={() => setDeleteTarget(pesquisa)}
                            />
                        ))}
                    </div>
                    {pesquisas.data.length === 0 ? (
                        <div className="p-10 text-center">
                            <PresentationGraphIcon className="mx-auto size-8 text-muted-foreground" />
                            <p className="mt-3 font-medium">
                                Nenhuma pesquisa encontrada
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Revise os filtros ou registre uma nova pesquisa
                                manual.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                                Exibindo {pesquisas.from}–{pesquisas.to} de{' '}
                                {pesquisas.total} pesquisa(s)
                            </div>
                            <PaginationLinks links={pesquisas.links} />
                        </>
                    )}
                </Surface>
            </PageContainer>

            <ResultsDialog
                pesquisa={resultsTarget}
                onClose={() => setResultsTarget(null)}
            />

            <Dialog
                open={deleteTarget !== null}
                onOpenChange={(open) => !open && setDeleteTarget(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Excluir pesquisa?</DialogTitle>
                        <DialogDescription>
                            {deleteTarget?.instituto ?? 'Esta pesquisa'} (
                            {deleteTarget && cargoLabels[deleteTarget.cargo]},{' '}
                            {deleteTarget?.uf}
                            {deleteTarget?.municipio
                                ? `/${deleteTarget.municipio}`
                                : ''}
                            ) será removida definitivamente, junto com seus
                            resultados e proveniência.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDeleteTarget(null)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="destructive-solid"
                            onClick={() => {
                                if (!deleteTarget) {
                                    return;
                                }

                                router.delete(
                                    `/admin/pesquisas-eleitorais/${deleteTarget.id}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setDeleteTarget(null),
                                    },
                                );
                            }}
                        >
                            Confirmar exclusão
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function PollRow({
    pesquisa,
    onEditResults,
    onDelete,
}: {
    pesquisa: PollCurationPesquisa;
    onEditResults: () => void;
    onDelete: () => void;
}) {
    const topResults = [...pesquisa.resultados]
        .sort((a, b) => b.percentual - a.percentual)
        .slice(0, 4);

    return (
        <article className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium">
                        {pesquisa.instituto ?? 'Instituto não informado'}
                    </p>
                    <Badge variant="outline">
                        {cargoLabels[pesquisa.cargo] ?? pesquisa.cargo}
                    </Badge>
                    <Badge variant="secondary">
                        {pesquisa.uf}
                        {pesquisa.municipio ? `/${pesquisa.municipio}` : ''}
                    </Badge>
                    {pesquisa.origem_provider && (
                        <Badge
                            variant={
                                pesquisa.origem_provider === 'manual'
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {pesquisa.origem_provider === 'manual'
                                ? 'Curadoria manual'
                                : pesquisa.origem_provider}
                            {pesquisa.confianca !== null &&
                                ` · confiança ${pesquisa.confianca}`}
                        </Badge>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    Publicada em{' '}
                    {new Date(
                        `${pesquisa.publicada_em}T00:00:00`,
                    ).toLocaleDateString('pt-BR')}{' '}
                    · {CENARIO_LABELS[pesquisa.cenario] ?? pesquisa.cenario}
                    {pesquisa.tamanho_amostra
                        ? ` · amostra ${pesquisa.tamanho_amostra}`
                        : ''}
                </p>
                {topResults.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {topResults
                            .map(
                                (result) =>
                                    `${result.nome}${result.partido ? ` (${result.partido})` : ''}: ${result.percentual}%`,
                            )
                            .join(' · ')}
                        {pesquisa.resultados.length > topResults.length &&
                            ` · +${pesquisa.resultados.length - topResults.length}`}
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground italic">
                        Sem resultados registrados ainda.
                    </p>
                )}
            </div>
            <div className="flex shrink-0 gap-2">
                <TableActionButton
                    variant="outline"
                    label={`Editar resultados de ${pesquisa.instituto ?? 'pesquisa'}`}
                    onClick={onEditResults}
                >
                    <PenIcon aria-hidden="true" />
                </TableActionButton>
                {pesquisa.deletable && (
                    <TableActionButton
                        variant="destructive"
                        label={`Excluir ${pesquisa.instituto ?? 'pesquisa'}`}
                        onClick={onDelete}
                    >
                        <TrashBinTrashIcon aria-hidden="true" />
                    </TableActionButton>
                )}
            </div>
        </article>
    );
}

function ResultsDialog({
    pesquisa,
    onClose,
}: {
    pesquisa: PollCurationPesquisa | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={pesquisa !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <ScrollableDialogContent className="sm:max-w-2xl">
                <ScrollableDialogHeader>
                    <DialogTitle>Editar resultados</DialogTitle>
                    <DialogDescription>
                        {pesquisa?.instituto} —{' '}
                        {pesquisa && cargoLabels[pesquisa.cargo]} ·{' '}
                        {pesquisa?.uf}
                        {pesquisa?.municipio ? `/${pesquisa.municipio}` : ''}. A
                        confiança atual desta pesquisa é{' '}
                        {pesquisa?.confianca ?? 'não definida'}.
                    </DialogDescription>
                </ScrollableDialogHeader>

                {pesquisa && (
                    <ResultsForm
                        key={pesquisa.id}
                        pesquisa={pesquisa}
                        onClose={onClose}
                    />
                )}
            </ScrollableDialogContent>
        </Dialog>
    );
}

function ResultsForm({
    pesquisa,
    onClose,
}: {
    pesquisa: PollCurationPesquisa;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<CandidateRowInput[]>(() =>
        pesquisa.resultados.length > 0
            ? pesquisa.resultados.map((result) => ({
                  key: result.external_candidate_id,
                  nome: result.nome,
                  partido: result.partido ?? '',
                  percentual: String(result.percentual),
                  candidato_politico_id: result.candidato_politico_id,
              }))
            : [emptyCandidateRow()],
    );
    const [provider, setProvider] = useState('');
    const [confidenceScore, setConfidenceScore] = useState('90');
    const [url, setUrl] = useState(pesquisa.fonte_url ?? '');
    const [observacao, setObservacao] = useState('');
    const [candidatesError, setCandidatesError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const { options: candidateOptions, loading: loadingCandidates } =
        usePollCandidateOptions({
            eleicaoId: pesquisa.eleicao_id,
            cargo: pesquisa.cargo,
            uf: pesquisa.cargo === 'presidente' ? '' : pesquisa.uf,
            municipio: pesquisa.municipio ?? '',
        });

    const submit = () => {
        const cleanedRows = rows.filter((row) => row.nome.trim() !== '');

        if (cleanedRows.length === 0) {
            setCandidatesError('Adicione ao menos um candidato.');

            return;
        }

        setCandidatesError('');
        setSubmitting(true);
        router.post(
            `/admin/pesquisas-eleitorais/${pesquisa.id}/resultados`,
            {
                provider,
                confidence_score: Number(confidenceScore),
                url: url || null,
                observacao: observacao || null,
                candidatos: cleanedRows.map((row) => ({
                    nome: row.nome.trim(),
                    partido: row.partido.trim() || null,
                    percentual: row.percentual,
                    candidato_politico_id: row.candidato_politico_id,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) => {
                    const message = Object.values(errors)[0];

                    if (message) {
                        setCandidatesError(message);
                    }
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <ScrollableDialogBody className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="provider">
                            Fonte (ex.: "AtlasIntel — PDF oficial")
                        </Label>
                        <Input
                            id="provider"
                            value={provider}
                            onChange={(event) =>
                                setProvider(event.target.value)
                            }
                            placeholder="Descreva de onde os números vieram"
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="confidence_score">
                            Confiança (1-100)
                        </Label>
                        <Input
                            id="confidence_score"
                            type="number"
                            min={1}
                            max={100}
                            value={confidenceScore}
                            onChange={(event) =>
                                setConfidenceScore(event.target.value)
                            }
                        />
                    </div>
                </div>
                <div className="space-y-1">
                    <Label htmlFor="url">URL da fonte (opcional)</Label>
                    <Input
                        id="url"
                        type="url"
                        value={url}
                        onChange={(event) => setUrl(event.target.value)}
                    />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="observacao">Observação (opcional)</Label>
                    <Textarea
                        id="observacao"
                        value={observacao}
                        onChange={(event) => setObservacao(event.target.value)}
                    />
                </div>
                <PollCandidateRows
                    rows={rows}
                    onChange={setRows}
                    candidateOptions={candidateOptions}
                    loadingCandidateOptions={loadingCandidates}
                    error={candidatesError}
                />
            </ScrollableDialogBody>

            <ScrollableDialogFooter>
                <Button variant="ghost" onClick={onClose}>
                    Cancelar
                </Button>
                <Button
                    onClick={submit}
                    disabled={submitting || provider.trim() === ''}
                >
                    Salvar resultados
                </Button>
            </ScrollableDialogFooter>
        </>
    );
}

PollCuration.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        {
            title: 'Pesquisas eleitorais',
            href: '/admin/pesquisas-eleitorais',
        },
    ],
};
