import { Badge } from '@/components/ui/badge';

const officeColors: Record<string, string> = {
    PRESIDENTE:
        'bg-violet-600/10 text-violet-600 dark:bg-violet-400/10 dark:text-violet-400',
    'VICE-PRESIDENTE':
        'bg-violet-600/10 text-violet-600 dark:bg-violet-400/10 dark:text-violet-400',
    GOVERNADOR:
        'bg-blue-600/10 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400',
    'VICE-GOVERNADOR':
        'bg-blue-600/10 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400',
    SENADOR:
        'bg-teal-600/10 text-teal-600 dark:bg-teal-400/10 dark:text-teal-400',
    'DEPUTADO FEDERAL':
        'bg-cyan-600/10 text-cyan-600 dark:bg-cyan-400/10 dark:text-cyan-400',
    'DEPUTADO ESTADUAL':
        'bg-sky-600/10 text-sky-600 dark:bg-sky-400/10 dark:text-sky-400',
    'DEPUTADO DISTRITAL':
        'bg-sky-600/10 text-sky-600 dark:bg-sky-400/10 dark:text-sky-400',
    PREFEITO:
        'bg-emerald-600/10 text-emerald-600 dark:bg-emerald-400/10 dark:text-emerald-400',
    'VICE-PREFEITO':
        'bg-emerald-600/10 text-emerald-600 dark:bg-emerald-400/10 dark:text-emerald-400',
    VEREADOR:
        'bg-amber-600/10 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400',
};
const fallbackColor = 'bg-muted-foreground/10 text-muted-foreground';

/**
 * O texto do cargo vem cru do TSE e varia de caixa entre datasets (ex.:
 * "VEREADOR" vindo de candidatos gerais, "Vereador" vindo de votação
 * nominal) — normaliza só pra escolher a cor, sem alterar o texto exibido.
 */
function normalizeOffice(office: string): string {
    return office.toUpperCase().trim();
}

/** "DEPUTADO ESTADUAL"/"deputado estadual" -> "Deputado Estadual". */
function toTitleCase(office: string): string {
    return office
        .toLowerCase()
        .replace(/(^|[\s-])\p{L}/gu, (letter) => letter.toUpperCase());
}

export function OfficeBadge({ office }: { office: string }) {
    const className = officeColors[normalizeOffice(office)] ?? fallbackColor;

    return <Badge className={className}>{toTitleCase(office)}</Badge>;
}
