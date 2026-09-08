import type { CSSProperties } from 'react';
import { Badge } from '@/components/ui/badge';

/**
 * Badge de partido colorido com a cor definida pelo root em
 * /admin/cores-partidos (ver PartidoCor). Sem cor cadastrada, cai de volta
 * pro outline neutro — não há mais o que fazer sem uma cor pra aplicar.
 */
export function PartyBadge({
    party,
    color,
}: {
    party: string;
    color: string | null;
}) {
    if (!color) {
        return (
            <Badge variant="outline" className="border-transparent">
                {party}
            </Badge>
        );
    }

    return (
        <Badge
            variant="outline"
            className="border-transparent"
            style={
                {
                    backgroundColor: `${color}1a`,
                    color,
                } satisfies CSSProperties
            }
        >
            {party}
        </Badge>
    );
}
