import type { ComponentProps, ReactNode } from 'react';

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
import { TrashBinTrashIcon } from '../icons';
import type { IconComponent } from '../types/icon';

export interface DestructiveAlertDialogProps extends Omit<
    ComponentProps<typeof AlertDialog>,
    'children'
> {
    title: ReactNode;
    description: ReactNode;
    confirmLabel?: ReactNode;
    cancelLabel?: ReactNode;
    icon?: IconComponent;
    onConfirm: () => void;
    confirmDisabled?: boolean;
    submitting?: boolean;
    children?: ReactNode;
}

export function DestructiveAlertDialog({
    title,
    description,
    confirmLabel = 'Excluir registro',
    cancelLabel = 'Cancelar',
    icon: Icon = TrashBinTrashIcon,
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
                    <AlertDialogMedia className="bg-destructive/10 text-destructive dark:bg-destructive/20">
                        <Icon aria-hidden="true" />
                    </AlertDialogMedia>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
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
