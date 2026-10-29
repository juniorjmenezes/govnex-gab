import { StatCard as BaseStatCard, StatCardSkeleton } from '@govnex/ui';
import type { StatCardProps } from '@govnex/ui';

import { cn } from '@/lib/utils';

export { StatCardSkeleton };
export type { StatCardProgress, StatCardProps, StatCardTrend } from '@govnex/ui';

/**
 * Ícone à esquerda em selo colorido e valor em fonte maior — só no GAB,
 * em avaliação, por ora.
 */
export function StatCard({ valueClassName, layout, ...props }: StatCardProps) {
    return (
        <BaseStatCard
            {...props}
            layout={layout ?? 'icon-leading'}
            valueClassName={cn('text-3xl', valueClassName)}
        />
    );
}
