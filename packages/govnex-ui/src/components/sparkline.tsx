import { useId } from 'react';
import { Area, AreaChart, ResponsiveContainer, YAxis } from 'recharts';

import { cn } from '../lib/utils';

export interface SparklineProps {
    /** Série curta, do mais antigo ao mais recente (ex.: 7–12 pontos). */
    data: readonly number[];
    /** Cor do traço/preenchimento (qualquer cor CSS). Padrão: destaque. */
    color?: string;
    /** Altura em px. */
    height?: number;
    /**
     * Nome acessível. Sem ele o gráfico é tratado como decorativo
     * (`aria-hidden`) — use quando o valor já está no texto ao lado.
     */
    label?: string;
    className?: string;
}

/**
 * Mini-gráfico de tendência, sem eixos nem interação: o valor e a variação
 * ficam no texto do cartão; o traço só mostra a forma da série. O ponto mais
 * recente é marcado para ancorar a leitura no período atual.
 */
export function Sparkline({
    data,
    color = 'var(--chart-1)',
    height = 40,
    label,
    className,
}: SparklineProps) {
    const gradientId = `sparkline-${useId().replace(/:/g, '')}`;

    if (data.length < 2) {
        return null;
    }

    const points = data.map((value, index) => ({ index, value }));
    const lastIndex = points.length - 1;

    return (
        <div
            data-slot="sparkline"
            className={cn('w-full', className)}
            style={{ height }}
            {...(label
                ? { role: 'img', 'aria-label': label }
                : { 'aria-hidden': true })}
        >
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                    data={points}
                    margin={{ top: 4, right: 4, bottom: 2, left: 0 }}
                    accessibilityLayer={false}
                >
                    <defs>
                        <linearGradient
                            id={gradientId}
                            x1="0"
                            y1="0"
                            x2="0"
                            y2="1"
                        >
                            <stop
                                offset="0%"
                                stopColor={color}
                                stopOpacity={0.22}
                            />
                            <stop
                                offset="100%"
                                stopColor={color}
                                stopOpacity={0}
                            />
                        </linearGradient>
                    </defs>
                    <YAxis hide domain={['dataMin', 'dataMax']} />
                    <Area
                        type="monotone"
                        dataKey="value"
                        stroke={color}
                        strokeWidth={2}
                        fill={`url(#${gradientId})`}
                        isAnimationActive={false}
                        dot={(props: {
                            cx?: number;
                            cy?: number;
                            index?: number;
                        }) =>
                            props.index === lastIndex &&
                            props.cx != null &&
                            props.cy != null ? (
                                <circle
                                    key="last"
                                    cx={props.cx}
                                    cy={props.cy}
                                    r={3}
                                    fill={color}
                                    stroke="var(--card)"
                                    strokeWidth={1.5}
                                />
                            ) : (
                                <g key={`dot-${props.index}`} />
                            )
                        }
                        activeDot={false}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}
