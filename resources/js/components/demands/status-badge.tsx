import { Badge } from '@/components/ui/badge';
import type { DemandStatus } from '@/types';

/**
 * O status é o indicador primário da linha e continua sólido, em contraste
 * deliberado com o `PriorityBadge`, que usa fundo suave por ser secundário.
 * Os tons foram fechados um passo (âmbar e esmeralda 600 → 700) porque com
 * `text-white` no tamanho `text-badge` os anteriores ficavam em 3,2:1 e
 * 3,8:1, abaixo dos 4,5:1 exigidos. "Encerrada" passou a usar contorno em
 * vez de um segundo cinza: no tema escuro era indistinguível de "Nova".
 */
const config: Record<DemandStatus, { label: string; className: string }> = {
    nova: {
        label: 'Nova',
        className: 'border-transparent bg-slate-600 text-white',
    },
    em_andamento: {
        label: 'Em andamento',
        className: 'border-transparent bg-indigo-600 text-white',
    },
    aguardando: {
        label: 'Aguardando',
        className: 'border-transparent bg-amber-700 text-white',
    },
    resolvida: {
        label: 'Resolvida',
        className: 'border-transparent bg-emerald-700 text-white',
    },
    encerrada: {
        label: 'Encerrada',
        className: 'border-border bg-transparent text-muted-foreground',
    },
};

export function StatusBadge({ status }: { status: DemandStatus }) {
    const item = config[status];

    return <Badge className={item.className}>{item.label}</Badge>;
}
