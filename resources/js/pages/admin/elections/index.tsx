import { Head, Link } from '@inertiajs/react';
import {
    ElectionForm,
    electionStatusBadges,
    electionTypeLabels,
    formatElectionDate,
} from '@/components/admin/election-form';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { CalendarDateIcon, SettingsIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AdminElection } from '@/types';

type ElectionRowData = AdminElection & { candidates_count: number };

export default function Elections({
    elections,
}: {
    elections: ElectionRowData[];
}) {
    return (
        <>
            <Head title="Eleições" />
            <PageContainer>
                <PageHeader
                    title="Eleições"
                    description="Cadastre as eleições gerais e municipais. Na tela de cada uma, sincronize os dados do TSE e apure o resultado."
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader help="O tipo condiciona quais datasets do TSE a eleição sincroniza e quais cargos ela apura. Depois de cadastrar, a tela da eleição abre para os próximos passos.">
                        <SurfaceTitle>Nova eleição</SurfaceTitle>
                    </SurfaceHeader>
                    <ElectionForm />
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    {elections.length === 0 ? (
                        <EmptyState
                            icon={CalendarDateIcon}
                            title="Nenhuma eleição cadastrada"
                            description="Cadastre a primeira eleição acima. Sem uma eleição cadastrada, não há dados do TSE para sincronizar."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Eleição</TableHead>
                                    <TableHead>1º turno</TableHead>
                                    <TableHead>2º turno</TableHead>
                                    <TableHead>Candidaturas</TableHead>
                                    <TableHead>Situação</TableHead>
                                    <TableHead className="text-right">
                                        Ações
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {elections.map((election) => (
                                    <ElectionRow
                                        key={election.id}
                                        election={election}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

function ElectionRow({ election }: { election: ElectionRowData }) {
    const badge = electionStatusBadges[election.situacao];
    const href = `/admin/eleicoes/${election.id}`;

    return (
        <TableRow>
            <TableCell className="whitespace-normal">
                <Link
                    href={href}
                    className="font-normal underline-offset-4 hover:underline"
                >
                    {election.nome}
                </Link>
                <p className="text-xs text-muted-foreground">
                    {election.ano} · {electionTypeLabels[election.tipo]}
                    {election.codigo_tse && ` · ${election.codigo_tse}`}
                </p>
            </TableCell>
            <TableCell>
                {formatElectionDate(election.primeiro_turno_em)}
            </TableCell>
            <TableCell>
                {formatElectionDate(election.segundo_turno_em)}
            </TableCell>
            <TableCell className="tabular-nums">
                {election.candidates_count > 0
                    ? election.candidates_count.toLocaleString('pt-BR')
                    : 'Não sincronizadas'}
            </TableCell>
            <TableCell>
                <Badge variant={badge.variant}>{badge.label}</Badge>
            </TableCell>
            <TableCell className="w-px">
                <div className="flex justify-end gap-2">
                    <TableActionButton
                        label={`Gerenciar ${election.nome}`}
                        asChild
                    >
                        <Link href={href}>
                            <SettingsIcon aria-hidden="true" />
                        </Link>
                    </TableActionButton>
                    <DeleteRecordButton
                        url={href}
                        label={`Remover ${election.nome}`}
                        title={`Remover ${election.nome}?`}
                        subject={election.nome}
                        subjectDetail={`${election.ano} · ${electionTypeLabels[election.tipo]}`}
                        description="Só é possível remover uma eleição que ainda não tem candidatos sincronizados."
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}

Elections.layout = {
    breadcrumbs: [{ title: 'Eleições', href: '/admin/eleicoes' }],
};
