import { StatCard as BaseStatCard, StatCardSkeleton } from '@govnex/ui';
import type { StatCardProps } from '@govnex/ui';

import { cn } from '@/lib/utils';

export { StatCardSkeleton };
export type { StatCardProgress, StatCardProps, StatCardTrend } from '@govnex/ui';

/**
 * Valor em fonte maior e sparkline no rodapé — só no GAB, em
 * avaliação, por ora. Todos os cards usam o layout `stacked`.
 */
export function StatCard({
    valueClassName,
    layout,
    chartPlacement,
    ...props
}: StatCardProps) {
    return (
        <BaseStatCard
            {...props}
            layout={layout ?? 'stacked'}
            chartPlacement={chartPlacement ?? 'footer'}
            valueClassName={cn('text-3xl', valueClassName)}
        />
    );
}
