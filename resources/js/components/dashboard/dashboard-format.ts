import { differenceInCalendarDays, format, parseISO } from 'date-fns';
import { ptBR } from 'date-fns/locale';

import type { StatCardTrend } from '@/components/common/stat-card';
import type { DashboardTrend, DashboardTrendKey } from '@/types';

export const formatShortDate = (value: string) =>
    format(parseISO(value), "dd 'de' MMM", { locale: ptBR });

export type DeadlineTone = 'danger' | 'warning' | 'neutral';

/**
 * Prazo relativo em linguagem natural ("Venceu há 3 dias", "Vence amanhã") e
 * o tom semântico do badge: vencido é `danger`; vencendo em até 2 dias,
 * `warning`; o resto fica neutro.
 */
export function describeDeadline(
    deadline: string | null,
    today: Date = new Date(),
): { label: string; tone: DeadlineTone } | null {
    if (!deadline) {
        return null;
    }

    const days = differenceInCalendarDays(parseISO(deadline), today);

    if (days < 0) {
        const late = -days;

        return {
            label: late === 1 ? 'Venceu ontem' : `Venceu há ${late} dias`,
            tone: 'danger',
        };
    }

    if (days === 0) {
        return { label: 'Vence hoje', tone: 'danger' };
    }

    return {
        label: days === 1 ? 'Vence amanhã' : `Vence em ${days} dias`,
        tone: days <= 2 ? 'warning' : 'neutral',
    };
}

/**
 * Polaridade de cada indicador: se subir é bom. Modelada aqui, num lugar só,
 * para a cor da variação nunca contradizer o sentido do número — mais
 * atrasadas, mais prazos apertados ou mais demandas em aberto (fila crescendo)
 * é ruim; mais resolvidas e mais cidadãos na base é bom.
 */
export const trendPolarity: Record<DashboardTrendKey, boolean> = {
    citizens: true,
    open_total: false,
    overdue: false,
    near_deadline: false,
    resolved_period: true,
};

/** Indicadores de fluxo comparam intervalos; os demais comparam estoques. */
const flowTrends: ReadonlySet<DashboardTrendKey> = new Set(['resolved_period']);

/**
 * Converte a tendência do backend na variação do `StatCard`. A diferença é
 * absoluta ("+2"), não percentual: as contagens do gabinete são pequenas
 * (1 → 2 viraria "+100%") e o período anterior pode ser zero.
 */
export function toStatTrend(
    key: DashboardTrendKey,
    trend: DashboardTrend | undefined,
    context: { period: number; start: string },
): StatCardTrend | undefined {
    if (!trend || trend.previous === null) {
        return undefined;
    }

    return {
        value: trend.current - trend.previous,
        format: 'number',
        positiveIsGood: trendPolarity[key],
        label: flowTrends.has(key)
            ? `vs. ${context.period} dias anteriores`
            : `desde ${format(parseISO(context.start), 'd MMM', { locale: ptBR })}`,
    };
}
