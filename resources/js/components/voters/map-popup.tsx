import type { ReactNode } from 'react';

/**
 * Peças comuns dos popups dos mapas (eleitores e prospecção). O popup não tem
 * padding próprio (ver leaflet-popup.css): cada bloco define o seu, e só o
 * cabeçalho reserva espaço à direita para o botão de fechar.
 */
export function PopupHeading({
    title,
    subtitle,
}: {
    title: string;
    subtitle: string;
}) {
    return (
        <div className="py-2.5 pr-9 pl-3">
            <p
                className="line-clamp-2 text-xs leading-snug font-medium"
                title={title}
            >
                {title}
            </p>
            <p
                className="mt-0.5 line-clamp-2 text-[11px] text-muted-foreground"
                title={subtitle}
            >
                {subtitle}
            </p>
        </div>
    );
}

export function PopupStat({
    label,
    title,
    children,
}: {
    label: string;
    title?: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-0 px-3 py-2" title={title}>
            <dt className="text-[11px] text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 flex items-center gap-1.5 text-sm font-semibold tabular-nums">
                {children}
            </dd>
        </div>
    );
}
