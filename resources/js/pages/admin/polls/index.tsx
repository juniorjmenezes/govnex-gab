import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { PaginationLinks } from '@/components/common/pagination-links';
import {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogFooter,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AddIcon,
    CloseIcon,
    EyeIcon,
    MagnifierIcon,
    PenIcon,
    PresentationGraphIcon,
    TrashBinTrashIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
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
import { Surface, surfaceClasses } from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { preservedListParams } from '@/lib/pagination';
import { CARGO_OPTIONS, CENARIO_LABELS } from '@/lib/poll-curation';
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
                {
                    eleicao_id: eleicaoId,
                    cargo,
                    uf,
                    q,
                    ...preservedListParams(),
                },
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

    const [viewTarget, setViewTarget] = useState<PollCurationPesquisa | null>(
        null,
    );
    const [deleteTarget, setDeleteTarget] =
        useState<PollCurationPesquisa | null>(null);

    return (
        <>
            <Head title="Pesquisas eleitorais" />
            <PageContainer>
                <PageHeader
                    title="Pesquisas eleitorais"
                    description="É possível registrar e corrigir pesquisas à mão, direto do PDF ou matéria original."
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
                    {pesquisas.data.length === 0 ? (
                        <EmptyState
                            icon={PresentationGraphIcon}
                            title="Nenhuma pesquisa encontrada"
                            description="Revise os filtros ou registre uma nova pesquisa manual."
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Instituto</TableHead>
                                        <TableHead>Cargo</TableHead>
                                        <TableHead>Origem</TableHead>
                                        <TableHead>Resultados</TableHead>
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {pesquisas.data.map((pesquisa) => (
                                        <PollRow
                                            key={pesquisa.id}
                                            pesquisa={pesquisa}
                                            onViewResults={() =>
                                                setViewTarget(pesquisa)
                                            }
                                            onEditResults={() =>
                                                router.get(
                                                    `/admin/pesquisas-eleitorais/${pesquisa.id}/editar`,
                                                )
                                            }
                                            onDelete={() =>
                                                setDeleteTarget(pesquisa)
                                            }
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                            <PaginationLinks
                                pagination={pesquisas}
                                label="pesquisa(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>

            <ResultsViewDialog
                pesquisa={viewTarget}
                onClose={() => setViewTarget(null)}
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

const formatDate = (value: string | null) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString('pt-BR') : null;

const territoryLabel = (pesquisa: PollCurationPesquisa) =>
    `${pesquisa.uf}${pesquisa.municipio ? `/${pesquisa.municipio}` : ''}`;

const sortedResults = (pesquisa: PollCurationPesquisa) =>
    [...pesquisa.resultados].sort((a, b) => b.percentual - a.percentual);

function PollRow({
    pesquisa,
    onViewResults,
    onEditResults,
    onDelete,
}: {
    pesquisa: PollCurationPesquisa;
    onViewResults: () => void;
    onEditResults: () => void;
    onDelete: () => void;
}) {
    const instituto = pesquisa.instituto ?? 'Instituto não informado';
    const leader = sortedResults(pesquisa)[0];

    return (
        <TableRow>
            <TableCell className="min-w-56 whitespace-normal">
                <p className="font-normal">{instituto}</p>
                <p className="text-xs text-muted-foreground">
                    {formatDate(pesquisa.publicada_em)} ·{' '}
                    {CENARIO_LABELS[pesquisa.cenario] ?? pesquisa.cenario}
                </p>
            </TableCell>
            <TableCell className="whitespace-normal">
                <p className="font-normal">
                    {cargoLabels[pesquisa.cargo] ?? pesquisa.cargo}
                </p>
                <p className="text-xs text-muted-foreground">
                    {territoryLabel(pesquisa)}
                </p>
            </TableCell>
            <TableCell className="whitespace-normal">
                {pesquisa.origem_provider ? (
                    <>
                        <p className="font-normal">
                            {pesquisa.origem_provider === 'manual'
                                ? 'Curadoria manual'
                                : pesquisa.origem_provider}
                        </p>
                        {pesquisa.confianca !== null && (
                            <p className="text-xs text-muted-foreground">
                                Confiança {pesquisa.confianca}
                            </p>
                        )}
                    </>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Não informada
                    </span>
                )}
            </TableCell>
            <TableCell className="whitespace-normal">
                {leader ? (
                    <>
                        <p className="font-normal tabular-nums">
                            {pesquisa.resultados.length}{' '}
                            {pesquisa.resultados.length === 1
                                ? 'candidato'
                                : 'candidatos'}
                        </p>
                        <p className="text-xs text-muted-foreground tabular-nums">
                            Lidera {leader.nome} ·{' '}
                            {leader.percentual.toLocaleString('pt-BR')}%
                        </p>
                    </>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Sem resultados
                    </span>
                )}
            </TableCell>
            <TableCell>
                <div className="flex justify-end gap-2">
                    <TableActionButton
                        label={`Ver resultados de ${instituto}`}
                        disabled={pesquisa.resultados.length === 0}
                        onClick={onViewResults}
                    >
                        <EyeIcon aria-hidden="true" />
                    </TableActionButton>
                    <TableActionButton
                        label={`Editar resultados de ${instituto}`}
                        onClick={onEditResults}
                    >
                        <PenIcon aria-hidden="true" />
                    </TableActionButton>
                    {pesquisa.deletable && (
                        <TableActionButton
                            variant="destructive"
                            label={`Excluir ${instituto}`}
                            onClick={onDelete}
                        >
                            <TrashBinTrashIcon aria-hidden="true" />
                        </TableActionButton>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * Consulta dos resultados, só leitura: ficha da pesquisa e a tabela de
 * candidatos em ordem de percentual. A edição fica na página própria
 * (admin/polls/edit).
 */
function ResultsViewDialog({
    pesquisa,
    onClose,
}: {
    pesquisa: PollCurationPesquisa | null;
    onClose: () => void;
}) {
    const coleta =
        pesquisa?.coleta_inicio_em && pesquisa.coleta_fim_em
            ? `${formatDate(pesquisa.coleta_inicio_em)} a ${formatDate(pesquisa.coleta_fim_em)}`
            : null;
    const details: [string, string | null][] = pesquisa
        ? [
              ['Publicada em', formatDate(pesquisa.publicada_em)],
              ['Coleta', coleta],
              ['Cenário', CENARIO_LABELS[pesquisa.cenario] ?? pesquisa.cenario],
              [
                  'Amostra',
                  pesquisa.tamanho_amostra
                      ? pesquisa.tamanho_amostra.toLocaleString('pt-BR')
                      : null,
              ],
              [
                  'Margem de erro',
                  pesquisa.margem_erro !== null
                      ? `${pesquisa.margem_erro.toLocaleString('pt-BR')} p.p.`
                      : null,
              ],
              ['Metodologia', pesquisa.metodologia],
          ]
        : [];

    return (
        <Dialog
            open={pesquisa !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <ScrollableDialogContent className="sm:max-w-2xl">
                <ScrollableDialogHeader>
                    <DialogTitle>
                        {pesquisa?.instituto ?? 'Resultados da pesquisa'}
                    </DialogTitle>
                    <DialogDescription>
                        {pesquisa &&
                            `${cargoLabels[pesquisa.cargo] ?? pesquisa.cargo} · ${territoryLabel(pesquisa)}`}
                    </DialogDescription>
                </ScrollableDialogHeader>

                {pesquisa && (
                    <ScrollableDialogBody className="space-y-5">
                        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                            {details
                                .filter(([, value]) => value)
                                .map(([label, value]) => (
                                    <div key={label} className="min-w-0">
                                        <dt className="text-xs text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="text-sm">{value}</dd>
                                    </div>
                                ))}
                        </dl>

                        <div className="overflow-hidden rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Candidato</TableHead>
                                        <TableHead>Partido</TableHead>
                                        <TableHead className="text-right">
                                            Percentual
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sortedResults(pesquisa).map((result) => (
                                        <TableRow
                                            key={result.external_candidate_id}
                                        >
                                            <TableCell className="whitespace-normal">
                                                {result.nome}
                                            </TableCell>
                                            <TableCell>
                                                {result.partido ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {result.percentual.toLocaleString(
                                                    'pt-BR',
                                                )}
                                                %
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        {pesquisa.fonte_url && (
                            <a
                                href={pesquisa.fonte_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-block text-xs break-all text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                {pesquisa.fonte_url}
                            </a>
                        )}
                    </ScrollableDialogBody>
                )}

                <ScrollableDialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Fechar
                    </Button>
                </ScrollableDialogFooter>
            </ScrollableDialogContent>
        </Dialog>
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
