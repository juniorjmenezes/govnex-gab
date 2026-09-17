import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { SyncStatusRow } from '@/components/admin/sync-progress';
import { TableActionButton } from '@/components/common/table-action-button';
import { TableGroupRow } from '@/components/common/table-group-row';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    BuildingsIcon,
    ForbiddenIcon,
    RefreshIcon,
    RestartIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import {
    Surface,
    SurfaceHeader,
    SurfaceTitle,
    SurfaceDescription,
} from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    datasetLabels,
    govnexDatasetGroups,
    isActiveSync,
} from '@/lib/political-sync';
import type { ElectionOption, GovnexDatasetOption } from '@/lib/political-sync';
import { cn } from '@/lib/utils';
import type { PoliticalDataSync, PollingDataOffice } from '@/types';

type Props = {
    globalSyncs: PoliticalDataSync[];
    politicsAvailable: boolean;
    elections: ElectionOption[];
    datasetSlugPatterns: Record<string, string>;
    pollingData: {
        year: number | null;
        offices: PollingDataOffice[];
    };
};

export default function PoliticalSync({
    globalSyncs,
    politicsAvailable,
    elections,
    datasetSlugPatterns,
    pollingData,
}: Props) {
    // Enquanto houver algum processamento pendente, atualiza os dados para que
    // o status visível reflita o progresso real sem precisar de um F5 manual.
    const hasActiveSyncs =
        globalSyncs.some(isActiveSync) ||
        pollingData.offices.some(
            (office) =>
                office.latest_sync !== null && isActiveSync(office.latest_sync),
        );

    useEffect(() => {
        if (!hasActiveSyncs) {
            return;
        }

        const { stop } = router.poll(4000, {
            only: ['globalSyncs', 'pollingData'],
        });

        return stop;
    }, [hasActiveSyncs]);

    return (
        <>
            <Head title="Sincronização política" />
            <PageContainer>
                <PageHeader
                    title="Sincronização política"
                    description="Sincronize os dados do TSE publicados na GOVNEX API e as pesquisas do PollingData."
                />

                {politicsAvailable ? (
                    <>
                        <GovnexApiDatasetsCard
                            syncs={globalSyncs}
                            elections={elections}
                            slugPatterns={datasetSlugPatterns}
                        />
                        <PollingDataCard
                            year={pollingData.year}
                            offices={pollingData.offices}
                        />
                    </>
                ) : (
                    <Surface as="section" className="overflow-hidden">
                        <EmptyState
                            icon={BuildingsIcon}
                            title="Nenhum gabinete com o módulo Política ativo"
                            description="Os dados políticos só são sincronizados quando ao menos um gabinete ativo usa o módulo Inteligência política. Ative-o em Gerenciar gabinetes."
                        />
                    </Surface>
                )}
            </PageContainer>
        </>
    );
}

/**
 * Os oito datasets do TSE num card só, na ordem em que dependem uns dos
 * outros. A eleição escolhida no cabeçalho vale para todas as linhas: cada
 * uma mostra o status daquele ano e o slug que a GOVNEX API precisa ter.
 */
function GovnexApiDatasetsCard({
    syncs,
    elections,
    slugPatterns,
}: {
    syncs: PoliticalDataSync[];
    elections: ElectionOption[];
    slugPatterns: Record<string, string>;
}) {
    const [year, setYear] = useState(() =>
        elections[0] ? String(elections[0].year) : '',
    );
    const election =
        elections.find((item) => String(item.year) === year) ?? null;

    return (
        <Surface as="section" className="overflow-hidden">
            <SurfaceHeader
                help="Cada dataset é localizado na GOVNEX API pelo nome indicado na linha. Sincronize de cima para baixo: cada grupo depende dos anteriores."
                actions={
                    <div className="min-w-0 flex-1 sm:w-56 sm:flex-none">
                        <AppSelect
                            id="govnex-election"
                            aria-label="Eleição"
                            value={year}
                            onValueChange={setYear}
                            options={elections.map((item) => ({
                                value: String(item.year),
                                label: item.label,
                            }))}
                            placeholder={
                                elections.length === 0
                                    ? 'Nenhuma eleição cadastrada'
                                    : 'Selecione'
                            }
                            disabled={elections.length === 0}
                            clearable={false}
                        />
                    </div>
                }
            >
                <SurfaceTitle>Dados do TSE — GOVNEX API</SurfaceTitle>
            </SurfaceHeader>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-2/5">Dataset</TableHead>
                        <TableHead>Última sincronização</TableHead>
                        <TableHead className="w-px text-right">Ações</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {govnexDatasetGroups.map((group) => (
                        <GovnexDatasetGroup
                            key={group.title}
                            title={group.title}
                            options={group.options}
                            election={election}
                            syncs={syncs}
                            slugPatterns={slugPatterns}
                        />
                    ))}
                </TableBody>
            </Table>
        </Surface>
    );
}

function GovnexDatasetGroup({
    title,
    options,
    election,
    syncs,
    slugPatterns,
}: {
    title: string;
    options: GovnexDatasetOption[];
    election: ElectionOption | null;
    syncs: PoliticalDataSync[];
    slugPatterns: Record<string, string>;
}) {
    return (
        <>
            <TableGroupRow title={title} colSpan={3} />
            {options.map((option) => (
                <GovnexDatasetRow
                    // A linha recomeça (erro e envio) ao trocar de eleição.
                    key={`${option.value}-${election?.year ?? 'sem-ano'}`}
                    option={option}
                    election={election}
                    syncs={syncs}
                    slugPattern={slugPatterns[option.value]}
                />
            ))}
        </>
    );
}

function GovnexDatasetRow({
    option,
    election,
    syncs,
    slugPattern,
}: {
    option: GovnexDatasetOption;
    election: ElectionOption | null;
    syncs: PoliticalDataSync[];
    slugPattern: string | undefined;
}) {
    const [submitting, setSubmitting] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [error, setError] = useState('');

    const label = datasetLabels[option.value] ?? option.value;
    const yearless = option.election === null;
    const year = yearless ? null : (election?.year ?? null);
    const applicable =
        yearless ||
        (election !== null &&
            (option.election === 'any' || option.election === election.type));
    const sync = syncs.find(
        (item) =>
            item.dataset === option.value && (yearless || item.year === year),
    );
    const active = sync !== undefined && isActiveSync(sync);
    const slug =
        year !== null
            ? slugPattern?.replace('{ano}', String(year))
            : slugPattern;

    const synchronize = () => {
        setError('');
        setSubmitting(true);
        router.post(
            '/admin/sincronizacoes-tse-globais/govnex-api',
            yearless
                ? { dataset: option.value }
                : { dataset: option.value, ano: year },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Não foi possível iniciar a sincronização.',
                    ),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const cancel = (syncId: number) => {
        setCancelling(true);
        router.post(
            `/admin/sincronizacoes-tse-globais/${syncId}/cancelar`,
            {},
            { preserveScroll: true, onFinish: () => setCancelling(false) },
        );
    };

    return (
        <TableRow>
            <TableCell className="min-w-72 whitespace-normal">
                <p
                    className={cn(
                        'font-normal',
                        !applicable && 'text-muted-foreground',
                    )}
                >
                    {label}
                </p>
                <p className="text-xs text-muted-foreground">
                    {option.description}
                </p>
                {slug && (
                    <p className="text-xs text-muted-foreground tabular-nums">
                        {slug}
                    </p>
                )}
                {error && <p className="text-xs text-destructive">{error}</p>}
            </TableCell>
            <TableCell className="min-w-56 whitespace-normal">
                {!applicable ? (
                    <p className="text-xs text-muted-foreground">
                        {notApplicableReason(option, election)}
                    </p>
                ) : sync ? (
                    <SyncStatusRow sync={sync} />
                ) : (
                    <p className="text-xs text-muted-foreground">
                        Nunca sincronizado
                    </p>
                )}
            </TableCell>
            <TableCell className="w-px">
                <div className="flex justify-end gap-2">
                    {applicable &&
                        (active && sync ? (
                            <TableActionButton
                                variant="destructive"
                                label={`Travou? Cancelar ${label}`}
                                disabled={cancelling}
                                onClick={() => cancel(sync.id)}
                            >
                                <ForbiddenIcon aria-hidden="true" />
                            </TableActionButton>
                        ) : (
                            <TableActionButton
                                label={`Sincronizar ${label}`}
                                disabled={submitting}
                                onClick={synchronize}
                            >
                                <RefreshIcon
                                    className={cn(submitting && 'animate-spin')}
                                    aria-hidden="true"
                                />
                            </TableActionButton>
                        ))}
                </div>
            </TableCell>
        </TableRow>
    );
}

function notApplicableReason(
    option: GovnexDatasetOption,
    election: ElectionOption | null,
): string {
    if (election === null) {
        return 'Selecione uma eleição.';
    }

    return option.election === 'municipal'
        ? `Só existe em eleição municipal — ${election.year} é eleição geral.`
        : `Só existe em eleição geral — ${election.year} é eleição municipal.`;
}

/**
 * O PollingData é sincronizado por gabinete: cada painel político lê a
 * execução do próprio gabinete. Por isso a tabela tem uma linha por gabinete
 * com o módulo Política ativo, em vez de um único botão global.
 */
function PollingDataCard({
    year,
    offices,
}: {
    year: number | null;
    offices: PollingDataOffice[];
}) {
    const [submittingId, setSubmittingId] = useState<number | null>(null);
    const [restartingId, setRestartingId] = useState<number | null>(null);
    const [cancelingId, setCancelingId] = useState<number | null>(null);

    const synchronize = (officeId: number) => {
        setSubmittingId(officeId);
        router.post(
            `/admin/gabinetes/${officeId}/sincronizacoes-tse`,
            { tasks: ['pollingdata_polls'] },
            { preserveScroll: true, onFinish: () => setSubmittingId(null) },
        );
    };

    const restartSync = (officeId: number, syncId: number) => {
        setRestartingId(syncId);
        router.post(
            `/admin/gabinetes/${officeId}/sincronizacoes-tse/${syncId}/reiniciar`,
            {},
            { preserveScroll: true, onFinish: () => setRestartingId(null) },
        );
    };

    const cancelSync = (officeId: number, syncId: number) => {
        setCancelingId(syncId);
        router.post(
            `/admin/gabinetes/${officeId}/sincronizacoes-tse/${syncId}/cancelar`,
            {},
            { preserveScroll: true, onFinish: () => setCancelingId(null) },
        );
    };

    return (
        <Surface as="section" className="overflow-hidden">
            <SurfaceHeader
                help={
                    year !== null
                        ? `Pesquisas nacionais de intenção de voto para presidente na eleição de ${year}. Não cobre governador, Senado ou prefeito.`
                        : undefined
                }
            >
                <SurfaceTitle>Pesquisas eleitorais — PollingData</SurfaceTitle>
                {year === null && (
                    <SurfaceDescription>
                        Nenhuma eleição geral cadastrada
                    </SurfaceDescription>
                )}
            </SurfaceHeader>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-2/5">Gabinete</TableHead>
                        <TableHead>Última sincronização</TableHead>
                        <TableHead className="w-px text-right">Ações</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {offices.map((office) => {
                        const sync = office.latest_sync;
                        const active = sync !== null && isActiveSync(sync);

                        return (
                            <TableRow key={office.id}>
                                <TableCell className="whitespace-normal">
                                    <p className="font-normal">{office.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            office.entidade,
                                            `${office.city}/${office.state}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </TableCell>
                                <TableCell className="min-w-64 whitespace-normal">
                                    {sync ? (
                                        <SyncStatusRow sync={sync} />
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            Nunca sincronizado
                                        </p>
                                    )}
                                </TableCell>
                                <TableCell className="w-px">
                                    <div className="flex justify-end gap-2">
                                        <TableActionButton
                                            label="Sincronizar pesquisas"
                                            disabled={
                                                year === null ||
                                                active ||
                                                submittingId === office.id
                                            }
                                            onClick={() =>
                                                synchronize(office.id)
                                            }
                                        >
                                            <RefreshIcon
                                                className={cn(
                                                    submittingId ===
                                                        office.id &&
                                                        'animate-spin',
                                                )}
                                                aria-hidden="true"
                                            />
                                        </TableActionButton>
                                        {sync?.status === 'pendente' && (
                                            <>
                                                <TableActionButton
                                                    label="Travou? Reiniciar"
                                                    disabled={
                                                        restartingId === sync.id
                                                    }
                                                    onClick={() =>
                                                        restartSync(
                                                            office.id,
                                                            sync.id,
                                                        )
                                                    }
                                                >
                                                    <RestartIcon
                                                        className={cn(
                                                            restartingId ===
                                                                sync.id &&
                                                                'animate-spin',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                </TableActionButton>
                                                <TableActionButton
                                                    variant="destructive"
                                                    label="Cancelar sincronização"
                                                    disabled={
                                                        cancelingId === sync.id
                                                    }
                                                    onClick={() =>
                                                        cancelSync(
                                                            office.id,
                                                            sync.id,
                                                        )
                                                    }
                                                >
                                                    <ForbiddenIcon aria-hidden="true" />
                                                </TableActionButton>
                                            </>
                                        )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </Surface>
    );
}

PoliticalSync.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        {
            title: 'Sincronização política',
            href: '/admin/sincronizacao-politica',
        },
    ],
};
