import { usePage } from '@inertiajs/react';
import { SquareArrowRightUpIcon } from '@/components/icons';

type Props = {
    text?: string;
};

/**
 * Aviso reutilizável para telas que viraram somente leitura porque a
 * pessoa/vínculo é gerido no Govnex Hub (equipe do gabinete, convites de
 * entidade). Sem `HUB_BASE_URL` configurada, mostra só o aviso, sem link —
 * mesmo padrão de [[HubStructureLink]].
 */
export function HubManagedHint({
    text = 'Gerenciado no Govnex Hub — altere lá.',
}: Props) {
    const { hubBaseUrl } = usePage().props;

    if (!hubBaseUrl) {
        return <p className="text-sm text-muted-foreground">{text}</p>;
    }

    return (
        <p className="text-sm text-muted-foreground">
            {text}{' '}
            <a
                href={hubBaseUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1 font-medium text-foreground underline underline-offset-2"
            >
                Abrir Govnex Hub
                <SquareArrowRightUpIcon
                    className="size-3.5"
                    aria-hidden="true"
                />
                <span className="sr-only"> (abre em nova aba)</span>
            </a>
        </p>
    );
}
