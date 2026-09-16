import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DestructiveAlertDialog } from '@/components/common/destructive-alert-dialog';
import { TableActionButton } from '@/components/common/table-action-button';
import { TrashBinTrashIcon } from '@/components/icons';

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
    const [submitting, setSubmitting] = useState(false);

    const destroy = () => {
        router.delete(url, {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: () => {
                setOpen(false);
                onSuccess?.();
            },
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
            <DestructiveAlertDialog
                open={open}
                onOpenChange={setOpen}
                title={title}
                description={description}
                submitting={submitting}
                confirmLabel={submitting ? 'Excluindo...' : 'Excluir registro'}
                onConfirm={destroy}
            />
        </>
    );
}
