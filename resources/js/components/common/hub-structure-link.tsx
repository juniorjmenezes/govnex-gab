import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { SquareArrowRightUpIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';

type Props = {
    label?: string;
    variant?: ComponentProps<typeof Button>['variant'];
    size?: ComponentProps<typeof Button>['size'];
};

/**
 * Substitui os antigos botões "Nova entidade"/"Novo gabinete": a estrutura é
 * criada no Govnex Hub e nasce no GAB pelo webhook. Sem `HUB_BASE_URL`
 * configurada, mostra só o aviso.
 */
export function HubStructureLink({
    label = 'Criar no Govnex Hub',
    variant = 'outline',
    size = 'default',
}: Props) {
    const { hubStructureUrl } = usePage().props;

    if (!hubStructureUrl) {
        return (
            <p className="text-sm text-muted-foreground">
                A estrutura é criada no Govnex Hub.
            </p>
        );
    }

    return (
        <Button variant={variant} size={size} asChild>
            <a
                href={hubStructureUrl}
                target="_blank"
                rel="noreferrer"
                title="A estrutura é criada no Govnex Hub e aparece aqui automaticamente."
            >
                <SquareArrowRightUpIcon className="size-4" aria-hidden="true" />
                {label}
                <span className="sr-only"> (abre em nova aba)</span>
            </a>
        </Button>
    );
}
