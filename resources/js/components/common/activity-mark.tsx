import { PauseIcon, PlayBoldIcon } from '@/components/icons';
/**
 * Marca de ativo/inativo das listagens: play na cor do gabinete quando ativo,
 * pause em cinza quando inativo. Ocupa a primeira coluna, sem cabeçalho, e
 * dispensa a coluna de situação, que gastava largura para dizer sim ou não.
 */
export function ActivityMark({
    active,
    activeLabel = 'Ativo',
    inactiveLabel = 'Inativo',
}: {
    active: boolean;
    activeLabel?: string;
    inactiveLabel?: string;
}) {
    const label = active ? activeLabel : inactiveLabel;

    return (
        <span className="inline-flex" title={label}>
            {active ? (
                <PlayBoldIcon
                    className="size-4 text-primary"
                    aria-hidden="true"
                />
            ) : (
                <PauseIcon
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
            )}
            <span className="sr-only">{label}</span>
        </span>
    );
}
