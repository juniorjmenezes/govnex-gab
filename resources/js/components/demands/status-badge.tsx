import { Badge } from '@/components/ui/badge';
import type { DemandStatus } from '@/types';

const config: Record<DemandStatus, { label: string; className: string }> = {
    nova: {
        label: 'Nova',
        className: 'bg-slate-500 text-white',
    },
    em_andamento: {
        label: 'Em andamento',
        className: 'bg-indigo-600 text-white',
    },
    aguardando: {
        label: 'Aguardando',
        className: 'bg-amber-600 text-white',
    },
    resolvida: {
        label: 'Resolvida',
        className: 'bg-emerald-600 text-white',
    },
    encerrada: {
        label: 'Encerrada',
        className: 'bg-slate-700 text-white',
    },
};

export function StatusBadge({ status }: { status: DemandStatus }) {
    const item = config[status];

    return <Badge className={item.className}>{item.label}</Badge>;
}
