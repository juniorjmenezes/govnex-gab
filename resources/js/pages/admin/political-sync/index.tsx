import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { SyncStatusRow } from '@/components/admin/sync-progress';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    BuildingsIcon,
    ForbiddenIcon,
    RefreshIcon,
    RestartIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
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
import { isActiveSync } from '@/lib/political-sync';
import { cn } from '@/lib/utils';
import type { PollingDataOffice } from '@/types';

type Props = {
    politicsAvailable: boolean;
    pollingData: {
        year: number | null;
        offices: PollingDataOffice[];
    };
};

/**
 * Só as pesquisas do PollingData, que são por gabinete. Os datasets públicos
 * do TSE fazem parte do ciclo de vida de cada eleição, em Eleições.
 */
export default function PollingDataSync({
    politicsAvailable,
    pollingData,
}: Props) {
    // Enquanto houver algum processamento pendente, atualiza os dados para que
    // o status visível reflita o progresso real sem precisar de um F5 manual.
    const hasActiveSyncs = pollingData.offices.some(
        (office) =>
            office.latest_sync !== null && isActiveSync(office.latest_sync),
    );

    useEffect(() => {
        if (!hasActiveSyncs) {
            return;
        }

        const { stop } = router.poll(4000, { only: ['pollingData'] });

        return stop;
    }, [hasActiveSyncs]);

    return (
        <>
            <Head title="Pesquisas do PollingData" />
            <PageContainer>
                <PageHeader
                    title="Pesquisas do PollingData"
                    description="Sincronize as pesquisas do PollingData de cada gabinete. Os dados do TSE ficam na tela de cada eleição, em Eleições."
                />

                {politicsAvailable ? (
                    <PollingDataCard
                        year={pollingData.year}
                        offices={pollingData.offices}
                    />
                ) : (
                    <Surface as="section" className="overflow-hidden">
                        <EmptyState
                            icon={BuildingsIcon}
                            title="Nenhum gabinete com o módulo Política ativo"
                            description="As pesquisas só são sincronizadas quando ao menos um gabinete ativo usa o módulo Inteligência política. Ative-o em Gerenciar gabinetes."
                        />
                    </Surface>
                )}
            </PageContainer>
        </>
    );
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

PollingDataSync.layout = {
    breadcrumbs: [
        {
            title: 'Pesquisas do PollingData',
            href: '/admin/sincronizacao-politica',
        },
    ],
};
