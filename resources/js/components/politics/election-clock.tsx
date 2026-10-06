import { useEffect, useMemo, useState } from 'react';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';

export interface ElectionClockProps {
    /** Rótulo do turno (ex.: "Eleições Gerais 2026 · 1º turno"). */
    label: string;
    /** Início da janela de votação, instante ISO 8601 (já no fuso do gabinete). */
    windowStart: string;
    /** Fim da janela de votação, instante ISO 8601. */
    windowEnd: string;
    /** Relógio do servidor no momento da resposta — âncora contra deriva do
     * relógio do navegador, o mesmo truque do `Countdown` do painel político. */
    serverNow: string;
    className?: string;
}

type Phase = 'before' | 'voting' | 'after';

function formatDuration(ms: number): string {
    const totalMinutes = Math.max(0, Math.round(ms / 60_000));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) {
        return `${minutes}min`;
    }

    if (minutes === 0) {
        return `${hours}h`;
    }

    return `${hours}h${String(minutes).padStart(2, '0')}min`;
}

/**
 * Andamento da votação (08h–17h) numa barra fina: uma linha com o rótulo do
 * turno e a legenda do momento, e a barra embaixo.
 */
export function ElectionClock({
    label,
    windowStart,
    windowEnd,
    serverNow,
    className,
}: ElectionClockProps) {
    const start = useMemo(() => new Date(windowStart).getTime(), [windowStart]);
    const end = useMemo(() => new Date(windowEnd).getTime(), [windowEnd]);
    const serverNowMs = useMemo(
        () => new Date(serverNow).getTime(),
        [serverNow],
    );
    // Primeira pintura usa o relógio do servidor cru; o efeito corrige para
    // a diferença com o relógio do navegador assim que monta (mesma ideia do
    // `Countdown` do painel político) — `Date.now()` só é lido em efeito,
    // nunca durante a renderização.
    const [now, setNow] = useState(serverNowMs);

    useEffect(() => {
        const drift = Date.now() - serverNowMs;
        const tick = () => setNow(Date.now() - drift);
        tick();
        const interval = window.setInterval(tick, 1_000);

        return () => window.clearInterval(interval);
    }, [serverNowMs]);

    const total = Math.max(1, end - start);
    const phase: Phase =
        now < start ? 'before' : now > end ? 'after' : 'voting';
    const elapsed = Math.min(total, Math.max(0, now - start));
    const percent =
        phase === 'before' ? 0 : phase === 'after' ? 1 : elapsed / total;

    const caption = {
        before: `Inicia em ${formatDuration(start - now)}`,
        voting: `${formatDuration(elapsed)} de 9h · termina em ${formatDuration(end - now)}`,
        after: 'Votação encerrada às 17h',
    }[phase];

    return (
        <div className={cn('flex w-full flex-col gap-2', className)}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p className="text-sm font-medium">{label}</p>
                <p className="text-xs text-muted-foreground tabular-nums">
                    Votação 08h–17h · {caption}
                </p>
            </div>
            <Progress
                value={Math.round(percent * 100)}
                aria-label={`Votação ${Math.round(percent * 100)}% decorrida`}
                className="h-2"
            />
        </div>
    );
}
