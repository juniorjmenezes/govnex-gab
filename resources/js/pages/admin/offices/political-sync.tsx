import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { SyncStatusRow } from '@/components/admin/sync-progress';
import { TableActionButton } from '@/components/common/table-action-button';
import {
    ArrowLeftIcon,
    DangerTriangleIcon,
    ForbiddenIcon,
    RefreshIcon,
    RestartIcon,
    ShieldCheckIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { isActiveSync, pollingDataSyncOptions } from '@/lib/political-sync';
import type { SyncOption, SyncTask } from '@/lib/political-sync';
import { cn } from '@/lib/utils';
import type {
    PoliticalDataSync,
    PoliticalSyncOffice,
    SyncTaskDefinition,
} from '@/types';

export default function PoliticalSync({
    office,
    politicalSyncs,
    syncTaskDefinitions,
}: {
    office: PoliticalSyncOffice;
    politicalSyncs: PoliticalDataSync[];
    syncTaskDefinitions: Record<string, SyncTaskDefinition>;
}) {
    const allTasks = pollingDataSyncOptions.map((option) => option.value);
    const [selectedTasks, setSelectedTasks] = useState<SyncTask[]>(allTasks);
    const [submitting, setSubmitting] = useState(false);
    const [restartingId, setRestartingId] = useState<number | null>(null);
    const [cancelingId, setCancelingId] = useState<number | null>(null);

    const findSync = (task: SyncTask): PoliticalDataSync | undefined => {
        const definition = syncTaskDefinitions[task];

        if (!definition) {
            return undefined;
        }

        return politicalSyncs.find(
            (item) =>
                item.dataset === definition.dataset &&
                item.year === definition.year,
        );
    };

    const hasActiveSyncs = politicalSyncs.some(isActiveSync);

    useEffect(() => {
        if (!hasActiveSyncs) {
            return;
        }

        const { stop } = router.poll(4000, { only: ['politicalSyncs'] });

        return stop;
    }, [hasActiveSyncs]);

    const toggleTask = (task: SyncTask, checked: boolean) => {
        setSelectedTasks((current) =>
            checked
                ? [...new Set([...current, task])]
                : current.filter((item) => item !== task),
        );
    };

    const synchronize = () => {
        if (selectedTasks.length === 0) {
            return;
        }

        setSubmitting(true);
        router.post(
            `/admin/gabinetes/${office.id}/sincronizacoes-tse`,
            { tasks: selectedTasks },
            { preserveScroll: true, onFinish: () => setSubmitting(false) },
        );
    };

    const restartSync = (syncId: number) => {
        setRestartingId(syncId);
        router.post(
            `/admin/gabinetes/${office.id}/sincronizacoes-tse/${syncId}/reiniciar`,
            {},
            { preserveScroll: true, onFinish: () => setRestartingId(null) },
        );
    };

    const cancelSync = (syncId: number) => {
        setCancelingId(syncId);
        router.post(
            `/admin/gabinetes/${office.id}/sincronizacoes-tse/${syncId}/cancelar`,
            {},
            { preserveScroll: true, onFinish: () => setCancelingId(null) },
        );
    };

    const inProgress = politicalSyncs.filter(isActiveSync).length;
    const failed = politicalSyncs.filter(
        (sync) => sync.status === 'falhou',
    ).length;

    const renderGroup = (
        title: string,
        description: string,
        options: SyncOption[],
    ) => (
        <Card className="gap-0 py-0">
            <div className="border-b p-4">
                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                    {title}
                </h2>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            <div className="m-5 divide-y rounded-xl border">
                {options.map((option) => {
                    const definition = syncTaskDefinitions[option.value];
                    const sync = findSync(option.value);

                    return (
                        <div
                            key={option.value}
                            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"
                        >
                            <div className="flex items-start gap-3 sm:w-72 sm:shrink-0">
                                <Switch
                                    id={`sync-${option.value}`}
                                    className="mt-0.5"
                                    checked={selectedTasks.includes(
                                        option.value,
                                    )}
                                    disabled={definition === undefined}
                                    onCheckedChange={(checked) =>
                                        toggleTask(option.value, checked)
                                    }
                                />
                                <Label
                                    htmlFor={`sync-${option.value}`}
                                    className="block min-w-0"
                                >
                                    <span className="block text-sm font-medium">
                                        {option.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {option.description}
                                    </span>
                                </Label>
                            </div>

                            <div className="min-w-0 flex-1">
                                {definition === undefined ? (
                                    <p className="text-xs text-muted-foreground">
                                        Indisponível: nenhuma eleição anterior
                                        cadastrada.
                                    </p>
                                ) : sync ? (
                                    <SyncStatusRow sync={sync} />
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Nunca sincronizado
                                    </p>
                                )}
                            </div>

                            {sync?.status === 'pendente' && (
                                <div className="flex shrink-0 gap-2 sm:justify-end">
                                    <TableActionButton
                                        label="Travou? Reiniciar"
                                        disabled={restartingId === sync.id}
                                        onClick={() => restartSync(sync.id)}
                                    >
                                        <RestartIcon
                                            className={cn(
                                                restartingId === sync.id &&
                                                    'animate-spin',
                                            )}
                                        />
                                    </TableActionButton>
                                    <TableActionButton
                                        variant="destructive"
                                        label="Cancelar sincronização"
                                        disabled={cancelingId === sync.id}
                                        onClick={() => cancelSync(sync.id)}
                                    >
                                        <ForbiddenIcon />
                                    </TableActionButton>
                                </div>
                            )}
                            {sync?.status === 'processando' && (
                                <p className="max-w-56 text-xs text-muted-foreground">
                                    Em processamento. Aguarde a conclusão ou a
                                    recuperação automática após o timeout.
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </Card>
    );

    return (
        <>
            <Head title={`Sincronizar dados políticos — ${office.name}`} />
            <PageContainer>
                <PageHeader
                    title="Sincronizar dados políticos"
                    description={`${office.name} · ${office.city}/${office.state}`}
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/admin/gabinetes">
                                <ArrowLeftIcon className="size-4" />
                                Voltar
                            </Link>
                        </Button>
                    }
                />

                <Card className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3">
                        {office.municipality_linked ? (
                            <ShieldCheckIcon
                                className="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                aria-hidden="true"
                            />
                        ) : (
                            <DangerTriangleIcon
                                className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
                                aria-hidden="true"
                            />
                        )}
                        <div>
                            <p className="text-sm font-medium">
                                {office.municipality_linked
                                    ? `Município vinculado ao TSE (${office.municipality_tse_code})`
                                    : 'Município ainda não vinculado ao TSE'}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {office.electorate_count !== null
                                    ? `${office.electorate_count.toLocaleString('pt-BR')} eleitores aptos`
                                    : 'Eleitorado ainda não sincronizado'}
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-4 text-sm">
                        {inProgress > 0 && (
                            <p className="text-primary">
                                {inProgress} em andamento
                            </p>
                        )}
                        {failed > 0 && (
                            <p className="text-destructive">
                                {failed} falharam
                            </p>
                        )}
                    </div>
                </Card>

                <Card className="p-5">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Dados oficiais do TSE
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        O TSE não é mais acessado automaticamente. O
                        administrador baixa cada ZIP pelo link oficial e faz o
                        upload manual na tela de gabinetes.
                    </p>
                    <Button asChild variant="outline" className="mt-4">
                        <Link href="/admin/gabinetes">
                            Abrir importação manual
                        </Link>
                    </Button>
                </Card>
                {renderGroup(
                    'Pesquisas eleitorais (PollingData)',
                    'Pesquisas de intenção de voto para presidente. Não cobre governador, senador ou prefeito.',
                    pollingDataSyncOptions,
                )}

                <div className="flex justify-end">
                    <Button
                        onClick={synchronize}
                        disabled={selectedTasks.length === 0 || submitting}
                    >
                        <RefreshIcon
                            className={cn(
                                'size-4',
                                submitting && 'animate-spin',
                            )}
                        />
                        {submitting
                            ? 'Enviando…'
                            : `Sincronizar selecionados (${selectedTasks.length})`}
                    </Button>
                </div>
            </PageContainer>
        </>
    );
}

PoliticalSync.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        { title: 'Sincronizar dados políticos', href: '' },
    ],
};
