import type { ComponentProps, ReactNode } from 'react';

import { Button } from '../components/button';
import { ArrowLeftIcon } from '../icons';

export interface BackButtonProps extends ComponentProps<typeof Button> {
    children?: ReactNode;
}

/**
 * Botão "Voltar" das telas de detalhe/sub-tela (criar, editar, mostrar) —
 * volta ao histórico do navegador, não a uma rota fixa: evita descrever de
 * novo, em cada tela, para onde "voltar" significa. Listagens de topo e o
 * painel não usam este botão — não há um "lugar anterior" óbvio para eles.
 */
export function BackButton({
    children = 'Voltar',
    variant = 'outline',
    onClick,
    ...props
}: BackButtonProps) {
    return (
        <Button
            type="button"
            variant={variant}
            onClick={(event) => {
                onClick?.(event);

                if (!event.defaultPrevented) {
                    window.history.back();
                }
            }}
            {...props}
        >
            <ArrowLeftIcon aria-hidden="true" />
            {children}
        </Button>
    );
}
