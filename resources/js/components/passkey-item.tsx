import { useState } from 'react';
import { DestructiveAlertDialog } from '@/components/common/destructive-alert-dialog';
import { KeyIcon, TrashBinTrashIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    onDelete: (id: number, onError: () => void) => void;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);
        onDelete(passkey.id, () => setIsDeleting(false));
    };

    return (
        <div className="flex items-center justify-between border-b p-4 last:border-b-0">
            <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-muted">
                    <KeyIcon className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="space-y-1">
                    <div className="flex items-center gap-2.5">
                        <p className="font-medium tracking-tight">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset">
                                {passkey.authenticator}
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Adicionada {passkey.created_at_diff}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="mx-1 text-muted-foreground/50">
                                    /
                                </span>
                                Último uso {passkey.last_used_at_diff}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={() => setDeleteOpen(true)}
            >
                <TrashBinTrashIcon className="h-4 w-4" />
                <span className="sr-only">Remover</span>
            </Button>
            <DestructiveAlertDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Remover chave de acesso?"
                description={
                    <>
                        A chave “{passkey.name}” não poderá mais ser usada para
                        entrar na conta.
                    </>
                }
                confirmLabel={isDeleting ? 'Removendo...' : 'Remover chave'}
                submitting={isDeleting}
                onConfirm={handleDelete}
            />
        </div>
    );
}
