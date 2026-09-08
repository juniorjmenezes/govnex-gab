import { Badge } from '@/components/ui/badge';
import type { DemandPriority } from '@/types';

const config: Record<DemandPriority, { label: string; className: string }> = {
    baixa: {
        label: 'Baixa',
        className:
            'bg-sky-600/10 text-sky-600 dark:bg-sky-400/10 dark:text-sky-400',
    },
    normal: {
        label: 'Normal',
        className: 'bg-muted-foreground/10 text-muted-foreground',
    },
    alta: {
        label: 'Alta',
        className:
            'bg-amber-600/10 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400',
    },
    urgente: {
        label: 'Urgente',
        className: 'bg-destructive text-white',
    },
};

export function PriorityBadge({ priority }: { priority: DemandPriority }) {
    const item = config[priority];

    return (
        <Badge className={item.className}>
            <span aria-hidden="true">{item.label.charAt(0)}</span>
            <span className="sr-only">{item.label}</span>
        </Badge>
    );
}
