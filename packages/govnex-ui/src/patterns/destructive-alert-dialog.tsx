import type { ComponentProps, ReactNode } from 'react';

import deleteWidgetGif from '../assets/delete-widget.gif';
import fullTrashGif from '../assets/full-trash.gif';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogMedia,
    AlertDialogTitle,
} from '../components/alert-dialog';
import { KeyIcon, TrashBinTrashIcon } from '../icons';
import { cn } from '../lib/utils';
import type { IconComponent } from '../types/icon';

/**
 * Animações do Icons8 (traço preto, fundo transparente), com o ícone estático
 * equivalente para quem prefere movimento reduzido.
 */
const animations = {
    trash: { src: fullTrashGif, fallback: TrashBinTrashIcon, className: '' },
    // Remoção de chave usa o "delete widget", no tamanho natural.
    key: { src: deleteWidgetGif, fallback: KeyIcon, className: '' },
} satisfies Record<
    string,
    { src: string; fallback: IconComponent; className: string }
>;

export type DestructiveAnimation = keyof typeof animations;

export interface DestructiveAlertDialogProps extends Omit<
    ComponentProps<typeof AlertDialog>,
    'children'
> {
    title: ReactNode;
    description: ReactNode;
    /**
     * O que será excluído (nome do cidadão, título do evento…), em destaque
     * no diálogo para evitar confirmar a exclusão do registro errado.
     */
    subject?: ReactNode;
    /** Contexto curto que diferencia registros parecidos (data, e-mail…). */
    subjectDetail?: ReactNode;
    confirmLabel?: ReactNode;
    cancelLabel?: ReactNode;
    /** Animação exibida no topo: lixeira (padrão) ou remoção de chave. */
    animation?: DestructiveAnimation;
    /** Ícone estático no lugar da animação, com o fundo destrutivo. */
    icon?: IconComponent;
    onConfirm: () => void;
    confirmDisabled?: boolean;
    submitting?: boolean;
    children?: ReactNode;
}

export function DestructiveAlertDialog({
    title,
    description,
    subject,
    subjectDetail,
    confirmLabel = 'Excluir registro',
    cancelLabel = 'Cancelar',
    animation = 'trash',
    icon: Icon,
    onConfirm,
    confirmDisabled = false,
    submitting = false,
    children,
    onOpenChange,
    ...props
}: DestructiveAlertDialogProps) {
    return (
        <AlertDialog
            onOpenChange={(open) => {
                if (!submitting || open) {
                    onOpenChange?.(open);
                }
            }}
            {...props}
        >
            <AlertDialogContent className="gap-0 overflow-hidden p-0">
                <AlertDialogHeader className="p-6">
                    <AlertDialogMedia
                        className={cn(
                            'text-destructive',
                            Icon
                                ? 'bg-destructive/10 dark:bg-destructive/20'
                                : // A animação dispensa o fundo e ocupa mais espaço.
                                  'size-14 bg-transparent',
                        )}
                    >
                        {Icon ? (
                            <Icon aria-hidden="true" />
                        ) : (
                            <DestructiveMedia animation={animation} />
                        )}
                    </AlertDialogMedia>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                    {subject && (
                        <div
                            data-slot="destructive-subject"
                            // Mesma coluna do título e da descrição: a primeira é do ícone.
                            className="col-start-2 mt-3 min-w-0 rounded-md border bg-muted/40 px-3 py-2 text-left"
                        >
                            <p className="text-sm font-medium break-words text-foreground">
                                {subject}
                            </p>
                            {subjectDetail && (
                                <p className="text-xs break-words text-muted-foreground">
                                    {subjectDetail}
                                </p>
                            )}
                        </div>
                    )}
                </AlertDialogHeader>

                {children && (
                    <div className="border-t px-6 py-5">{children}</div>
                )}

                <AlertDialogFooter className="border-t bg-muted/30 p-4">
                    <AlertDialogCancel
                        className="w-full sm:w-auto sm:min-w-28"
                        disabled={submitting}
                    >
                        {cancelLabel}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="w-full sm:w-auto sm:min-w-36"
                        variant="destructive-solid"
                        disabled={confirmDisabled || submitting}
                        aria-busy={submitting}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * O GIF pode trazer fundo branco em alguns quadros. A mesclagem some com ele:
 * `multiply` no tema claro e, depois da inversão, `screen` no escuro. Com
 * movimento reduzido, cai para o ícone estático equivalente.
 */
function DestructiveMedia({ animation }: { animation: DestructiveAnimation }) {
    const { src, fallback: Fallback, className } = animations[animation];

    return (
        <>
            <img
                src={src}
                alt=""
                aria-hidden="true"
                width={56}
                height={56}
                className={cn(
                    'size-14 mix-blend-multiply motion-reduce:hidden dark:mix-blend-screen dark:invert',
                    className,
                )}
            />
            <Fallback
                aria-hidden="true"
                className="hidden size-8 motion-reduce:block"
            />
        </>
    );
}
