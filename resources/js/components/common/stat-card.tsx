import type { ReactNode } from 'react';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import type { IconComponent } from '@/types/icon';

interface StatCardProgress {
    percent: number;
    label: string;
    value: string | number;
}

interface StatCardProps {
    title: string;
    value: string | number;
    description?: ReactNode;
    progress?: StatCardProgress;
    icon: IconComponent;
    /** Destaque pontual do número, quando o valor em si é um alerta. */
    valueClassName?: string;
}

export function StatCard({
    title,
    value,
    description,
    progress,
    icon: Icon,
    valueClassName,
}: StatCardProps) {
    return (
        <Card className="min-w-0 gap-0 py-0">
            <CardContent className="p-5">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <CardTitle className="text-muted-foreground">
                            {title}
                        </CardTitle>
                        <p
                            className={cn(
                                'mt-2 font-mono text-3xl font-bold tracking-tight text-foreground tabular-nums',
                                valueClassName,
                            )}
                        >
                            {value}
                        </p>
                    </div>
                    <Icon
                        className="size-7 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                {progress ? (
                    <div className="mt-3">
                        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>{progress.label}</span>
                            <span className="font-mono font-medium text-foreground tabular-nums">
                                {progress.value}
                            </span>
                        </div>
                        <Progress
                            value={Math.min(100, Math.max(0, progress.percent))}
                            className="mt-2"
                        />
                    </div>
                ) : description ? (
                    <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
