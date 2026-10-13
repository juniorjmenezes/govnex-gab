import { Head, router } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import {
    ElectionForm,
    electionStatusBadges,
    electionTypeLabels,
    formatElectionDate,
} from '@/components/admin/election-form';
import { SyncStatusRow } from '@/components/admin/sync-progress';
import { TableActionButton } from '@/components/common/table-action-button';
import { TableGroupRow } from '@/components/common/table-group-row';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    BuildingsIcon,
    ChecklistIcon,
    ForbiddenIcon,
    RefreshIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
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
import { cn } from '@/lib/utils';
import type {
    AdminElection,
    ElectionDataset,
    ElectionTallyOffice,
} from '@/types';

type Props = {
    election: AdminElection;
    politicsAvailable: boolean;
    datasets: ElectionDataset[];
    tally: {
        available: boolean;
        offices: ElectionTallyOffice[];
    };
};

/**
 * A eleição como um corpo só, na ordem do ciclo: cadastro, dados do TSE que
 * valem para o tipo dela e apuração.
 */
export default function ElectionShow({
    election,
    politicsAvailable,
    datasets,
    tally,
}: Props) {
    const badge = electionStatusBadges[election.situacao];
    const hasActiveSyncs = datasets.some(
        (dataset) =>
            dataset.latest_sync !== null && isActiveSync(dataset.latest_sync),
    );

    // Enquanto houver sincronização na fila ou em curso, atualiza o status
    // sem precisar de F5.
    useEffect(() => {
        if (!hasActiveSyncs) {
            return;
        }

        const { stop } = router.poll(4000, { only: ['datasets', 'tally'] });

        return stop;
    }, [hasActiveSyncs]);

    const rounds = [
        `1º turno ${formatElectionDate(election.primeiro_turno_em)}`,
        election.segundo_turno_em &&
            `2º turno ${formatElectionDate(election.segundo_turno_em)}`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title={election.nome} />
            <PageContainer>
                <PageHeader
                    title={election.nome}
                    description={`${election.ano} · ${electionTypeLabels[election.tipo]} · ${rounds}`}
                    actions={
                        <Badge variant={badge.variant}>{badge.label}</Badge>
                    }
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader help="Mudar o ano ou o tipo de uma eleição já sincronizada não move os dados: eles continuam no ano em que foram importados.">
                        <SurfaceTitle>Cadastro</SurfaceTitle>
                    </SurfaceHeader>
                    <ElectionForm election={election} />
                </Surface>

                <DatasetsCard
                    election={election}
                    datasets={datasets}
                    politicsAvailable={politicsAvailable}
                />

                <TallyCard election={election} tally={tally} />
            </PageContainer>
        </>
    );
}

function DatasetsCard({
    election,
    datasets,
    politicsAvailable,
}: {
    election: AdminElection;
    datasets: ElectionDataset[];
    politicsAvailable: boolean;
}) {
    const byKey = new Map(datasets.map((dataset) => [dataset.key, dataset]));
    // Só os grupos com algum dataset desta eleição, na ordem de dependência.
    const groups = govnexDatasetGroups
        .map((group) => ({
            title: group.title,
            rows: group.options.flatMap((option) => {
                const dataset = byKey.get(option.value);

                return dataset
                    ? [{ dataset, description: option.description }]
                    : [];
            }),
        }))
        .filter((group) => group.rows.length > 0);

    return (
        <Surface as="section" className="overflow-hidden">
            <SurfaceHeader
                help={`Só aparecem os datasets que valem para ${electionTypeLabels[election.tipo].toLocaleLowerCase('pt-BR')}. Sincronize de cima para baixo: cada grupo depende dos anteriores. Cada dataset é localizado na GOVNEX API pelo nome indicado na linha.`}
            >
                <SurfaceTitle>Dados do TSE</SurfaceTitle>
                <SurfaceDescription>
                    {datasets.length} datasets · GOVNEX API
                </SurfaceDescription>
            </SurfaceHeader>
            {politicsAvailable ? (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-2/5">Dataset</TableHead>
                            <TableHead>Última sincronização</TableHead>
                            <TableHead className="w-px text-right">
                                Ações
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groups.map((group) => (
                            <Fragment key={group.title}>
                                <TableGroupRow
                                    title={group.title}
                                    colSpan={3}
                                />
                                {group.rows.map(({ dataset, description }) => (
                                    <DatasetRow
                                        key={dataset.key}
                                        election={election}
                                        dataset={dataset}
                                        description={description}
                                    />
                                ))}
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            ) : (
                <EmptyState
                    icon={BuildingsIcon}
                    title="Nenhum gabinete com o módulo Política ativo"
                    description="Os dados do TSE só são sincronizados quando ao menos um gabinete ativo usa o módulo Inteligência política. Ative-o em Gerenciar gabinetes."
                />
            )}
        </Surface>
    );
}

function DatasetRow({
    election,
    dataset,
    description,
}: {
    election: AdminElection;
    dataset: ElectionDataset;
    description: string;
}) {
    const [submitting, setSubmitting] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [error, setError] = useState('');

    const label = datasetLabels[dataset.key] ?? dataset.key;
    const sync = dataset.latest_sync;
    const active = sync !== null && isActiveSync(sync);

    const synchronize = () => {
        setError('');
        setSubmitting(true);
        router.post(
            `/admin/eleicoes/${election.id}/sincronizacoes`,
            { dataset: dataset.key },
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
            `/admin/eleicoes/${election.id}/sincronizacoes/${syncId}/cancelar`,
            {},
            { preserveScroll: true, onFinish: () => setCancelling(false) },
        );
    };

    return (
        <TableRow>
            <TableCell className="min-w-72 whitespace-normal">
                <p className="font-normal">{label}</p>
                <p className="text-xs text-muted-foreground">{description}</p>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {dataset.slug}
                    {!dataset.requires_year && ' · base permanente, sem ano'}
                </p>
                {error && <p className="text-xs text-destructive">{error}</p>}
            </TableCell>
            <TableCell className="min-w-56 whitespace-normal">
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
                    {active && sync ? (
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
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

const sourceLabels: Record<ElectionTallyOffice['source'], string> = {
    tse: 'Apuração do TSE',
    candidate_votes: 'Votação nominal',
};

function TallyCard({
    election,
    tally,
}: {
    election: AdminElection;
    tally: Props['tally'];
}) {
    const [submitting, setSubmitting] = useState(false);

    const apurar = () => {
        setSubmitting(true);
        router.post(
            `/admin/eleicoes/${election.id}/apurar`,
            {},
            { preserveScroll: true, onFinish: () => setSubmitting(false) },
        );
    };

    const help =
        election.tipo === 'geral'
            ? 'Apurar lê a apuração do TSE e grava, com 100% das seções apuradas, o resultado de presidente, governador, senador e deputados; depois classifica quem foi ao 2º turno e grava o 2º turno. Durante a eleição, roda sozinha de hora em hora.'
            : 'Em eleição municipal, o resultado de prefeito e vereador vem do dataset Votação nominal, acima. Apurar classifica quem foi ao 2º turno de prefeito. Durante a eleição, roda sozinha de hora em hora.';

    return (
        <Surface as="section" className="overflow-hidden">
            <SurfaceHeader
                help={help}
                actions={
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!tally.available || submitting}
                        onClick={apurar}
                    >
                        <ChecklistIcon
                            className={cn(submitting && 'animate-pulse')}
                            aria-hidden="true"
                        />
                        Apurar agora
                    </Button>
                }
            >
                <SurfaceTitle>Apuração</SurfaceTitle>
                <SurfaceDescription>
                    {tally.available
                        ? 'resultado gravado por cargo'
                        : 'o 1º turno ainda não aconteceu'}
                </SurfaceDescription>
            </SurfaceHeader>
            {tally.offices.length === 0 ? (
                <EmptyState
                    icon={UsersGroupRoundedIcon}
                    title="Nenhuma candidatura sincronizada"
                    description="Sincronize as candidaturas em Dados do TSE para acompanhar o resultado de cada cargo."
                />
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Cargo</TableHead>
                            <TableHead className="text-right">
                                Candidaturas
                            </TableHead>
                            <TableHead className="text-right">
                                Com resultado
                            </TableHead>
                            <TableHead className="text-right">
                                Eleitos
                            </TableHead>
                            <TableHead>Fonte</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tally.offices.map((office) => (
                            <TableRow key={office.office}>
                                <TableCell>{office.label}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {office.candidates.toLocaleString('pt-BR')}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {office.with_result.toLocaleString('pt-BR')}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {office.elected.toLocaleString('pt-BR')}
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    <p>{sourceLabels[office.source]}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {office.updated_at
                                            ? `Gravado em ${new Date(office.updated_at).toLocaleString('pt-BR')}`
                                            : 'Sem resultado gravado'}
                                    </p>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Surface>
    );
}

ElectionShow.layout = {
    breadcrumbs: [
        { title: 'Eleições', href: '/admin/eleicoes' },
        { title: 'Eleição', href: '#' },
    ],
};
