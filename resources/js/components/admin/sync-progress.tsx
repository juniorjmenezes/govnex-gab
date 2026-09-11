import { Progress } from '@/components/ui/progress';
import {
    datasetLabels,
    syncStatusDetail,
    syncStatusLabels,
} from '@/lib/political-sync';
import { cn } from '@/lib/utils';
import type { PoliticalDataSync } from '@/types';

const statusDotClassNames: Record<PoliticalDataSync['status'], string> = {
    concluida: 'bg-emerald-500 dark:bg-emerald-400',
    falhou: 'bg-destructive',
    cancelada: 'bg-muted-foreground/50',
    processando: 'bg-primary animate-pulse',
    pendente: 'bg-muted-foreground/50',
};

/**
 * Dot de estado — substitui o badge de texto: mais compacto e permite
 * alinhar o detalhe (horário, contagem, percentual) à direita na mesma
 * linha, em vez de disputar espaço com um badge antes dele.
 */
export function SyncStatusDot({
    status,
    className,
}: {
    status: PoliticalDataSync['status'];
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'size-2 shrink-0 rounded-full',
                statusDotClassNames[status] ?? statusDotClassNames.pendente,
                className,
            )}
        />
    );
}

export function SyncProgress({
    sync,
    className,
}: {
    sync: PoliticalDataSync;
    className?: string;
}) {
    const value =
        sync.status === 'concluida' ||
        sync.status === 'falhou' ||
        sync.status === 'cancelada'
            ? 100
            : sync.status === 'processando' && sync.progress_percent !== null
              ? sync.progress_percent
              : sync.status === 'pendente'
                ? 8
                : null;

    return (
        <Progress
            value={value}
            getAriaValueText={() =>
                syncStatusLabels[sync.status] ?? sync.status
            }
            aria-label={`Progresso de ${datasetLabels[sync.dataset] ?? sync.dataset}`}
            className={className}
        />
    );
}

/**
 * Linha padrão de status de sincronização: dot colorido à esquerda, detalhe
 * (horário/contagem/percentual) alinhado à direita, barra de progresso real
 * embaixo. Usada em todo lugar que hoje mostraria um badge + progresso —
 * datasets do TSE pela GOVNEX API e pesquisas do PollingData.
 */
export function SyncStatusRow({
    sync,
    className,
}: {
    sync: PoliticalDataSync;
    className?: string;
}) {
    // O erro de uma sincronização é uma frase inteira (o que houve e o que
    // fazer): vai numa linha própria, quebrando dentro da coluna, em vez de
    // espremido à direita do status.
    const failure =
        (sync.status === 'falhou' || sync.status === 'cancelada') && sync.error
            ? sync.error
            : null;

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-center justify-between gap-3 text-xs">
                <span className="flex min-w-0 items-center gap-1.5 font-medium tracking-wide uppercase">
                    <SyncStatusDot status={sync.status} />
                    <span className="truncate">
                        {syncStatusLabels[sync.status] ?? sync.status}
                    </span>
                </span>
                {failure === null && (
                    <span className="shrink-0 text-right text-muted-foreground">
                        {syncStatusDetail(sync)}
                    </span>
                )}
            </div>
            <SyncProgress sync={sync} />
            {failure !== null && (
                <p
                    className={cn(
                        'text-xs break-words whitespace-normal',
                        sync.status === 'falhou'
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {failure}
                </p>
            )}
        </div>
    );
}
