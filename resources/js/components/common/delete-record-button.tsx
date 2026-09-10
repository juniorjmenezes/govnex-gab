import { router } from '@inertiajs/react';
import { useState } from 'react';
import { TableActionButton } from '@/components/common/table-action-button';
import { TrashBinTrashIcon } from '@/components/icons';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

type DeleteRecordButtonProps = {
    url: string;
    label: string;
    title: string;
    description: string;
    onSuccess?: () => void;
};

export function DeleteRecordButton({
    url,
    label,
    title,
    description,
    onSuccess,
}: DeleteRecordButtonProps) {
    const [open, setOpen] = useState(false);

    const destroy = () => {
        router.delete(url, {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(),
        });
    };

    return (
        <>
            <TableActionButton
                type="button"
                variant="destructive"
                label={label}
                onClick={() => setOpen(true)}
            >
                <TrashBinTrashIcon aria-hidden="true" />
            </TableActionButton>
            <AlertDialog open={open} onOpenChange={setOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{title}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {description}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={destroy}
                        >
                            Excluir registro
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
