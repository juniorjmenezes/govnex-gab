import { StatCard as BaseStatCard, StatCardSkeleton } from '@govnex/ui';
import type { StatCardProps } from '@govnex/ui';

import { cn } from '@/lib/utils';

export { StatCardSkeleton };
export type { StatCardProgress, StatCardProps, StatCardTrend } from '@govnex/ui';

/**
 * Valor em fonte maior — só no GAB, em avaliação, por ora. Cards com
 * sparkline usam o layout `icon-leading`; sem gráfico, usam o padrão
 * `stacked` do pacote.
 */
export function StatCard({
    valueClassName,
    layout,
    sparkline,
    ...props
}: StatCardProps) {
    const hasSparkline = sparkline !== undefined && sparkline.length > 1;

    return (
        <BaseStatCard
            {...props}
            sparkline={sparkline}
            layout={layout ?? (hasSparkline ? 'icon-leading' : 'stacked')}
            valueClassName={cn('text-3xl', valueClassName)}
        />
    );
}
