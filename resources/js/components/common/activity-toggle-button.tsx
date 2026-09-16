import type { ComponentProps } from 'react';
import { TableActionButton } from '@/components/common/table-action-button';
import { PauseIcon, PlayIcon } from '@/components/icons';

/**
 * Botão de ativar/desativar das listagens. O ícone mostra a ação disponível —
 * pause em um registro ativo, play em um inativo — acompanhando a marca de
 * situação da primeira coluna ([[ActivityMark]]).
 */
export function ActivityToggleButton({
    active,
    name,
    activeAction = 'Desativar',
    inactiveAction = 'Ativar',
    ...props
}: Omit<
    ComponentProps<typeof TableActionButton>,
    'label' | 'children' | 'variant'
> & {
    active: boolean;
    /** Nome do registro, usado no rótulo acessível e na dica. */
    name: string;
    /** Verbos da ação, quando a listagem usa outros (ex.: suspender). */
    activeAction?: string;
    inactiveAction?: string;
}) {
    return (
        <TableActionButton
            label={`${active ? activeAction : inactiveAction} ${name}`}
            variant={active ? 'destructive' : 'outline'}
            {...props}
        >
            {active ? (
                <PauseIcon aria-hidden="true" />
            ) : (
                <PlayIcon aria-hidden="true" />
            )}
        </TableActionButton>
    );
}
