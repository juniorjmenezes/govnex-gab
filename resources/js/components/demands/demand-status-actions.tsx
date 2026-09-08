import { Link, router } from '@inertiajs/react';
import {
    ArrowRightIcon,
    ChecklistIcon,
    CloseIcon,
    LockKeyholeIcon,
    MenuDotsIcon,
    PenIcon,
    RestartIcon,
    TrashBinTrashIcon,
} from '@solar-icons/react/outline';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Demand, SelectOption } from '@/types';

/** Código de 6 dígitos que o usuário precisa digitar para confirmar a exclusão. */
const generateConfirmationCode = () =>
    String(Math.floor(100000 + Math.random() * 900000));

type DialogKind = 'resolve' | 'reopen' | null;

/**
 * Ações de ciclo de vida raramente usadas (resolver/encerrar/reabrir/
 * excluir) ficam num menu secundário — não competem visualmente com
 * "Adicionar atualização" e "Encaminhar", que são as ações do dia a dia.
 */
export function DemandStatusActions({
    demand,
    allowedTransitions,
    resultados,
    canDelete,
}: {
    demand: Demand;
    allowedTransitions: SelectOption[];
    resultados: SelectOption[];
    canDelete: boolean;
}) {
    const [dialog, setDialog] = useState<DialogKind>(null);
    const [resultado, setResultado] = useState('');
    const [descricao, setDescricao] = useState('');
    const [descricaoError, setDescricaoError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const [closeOpen, setCloseOpen] = useState(false);
    const [closeDescricao, setCloseDescricao] = useState('');
    const [closeDescricaoError, setCloseDescricaoError] = useState('');
    const [closeSubmitting, setCloseSubmitting] = useState(false);

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteCode, setDeleteCode] = useState('');
    const [deleteInput, setDeleteInput] = useState('');
    const [deleteSubmitting, setDeleteSubmitting] = useState(false);

    const canResolve = demand.status !== 'resolvida';
    const canClose = demand.status !== 'encerrada';
    const canReopen = ['resolvida', 'encerrada'].includes(demand.status);
    const plainTransitions = allowedTransitions.filter(
        (item) => !['resolvida', 'encerrada'].includes(item.value),
    );

    const moveTo = (status: string) => {
        router.patch(
            `/demandas/${demand.id}/status`,
            { status },
            { preserveScroll: true },
        );
    };

    const close = () => {
        setDialog(null);
        setResultado('');
        setDescricao('');
        setDescricaoError('');
    };

    const confirm = () => {
        if (!dialog) {
            return;
        }

        if (dialog === 'reopen' && descricao.trim() === '') {
            setDescricaoError('Informe o motivo da reabertura.');

            return;
        }

        setDescricaoError('');
        setSubmitting(true);
        const url = `/demandas/${demand.id}/${{ resolve: 'resolver', reopen: 'reabrir' }[dialog]}`;
        const payload =
            dialog === 'resolve'
                ? { resultado: resultado || null, descricao: descricao || null }
                : { motivo: descricao };

        router.patch(url, payload, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
            onSuccess: close,
            onError: (errors) => {
                const message =
                    dialog === 'reopen' ? errors.motivo : errors.descricao;

                if (message) {
                    setDescricaoError(message);
                }
            },
        });
    };

    const closeDrawer = () => {
        setCloseOpen(false);
        setCloseDescricao('');
        setCloseDescricaoError('');
    };

    const confirmClose = () => {
        if (closeDescricao.trim() === '') {
            setCloseDescricaoError(
                'Informe a descrição final do encerramento.',
            );

            return;
        }

        setCloseDescricaoError('');
        setCloseSubmitting(true);
        router.patch(
            `/demandas/${demand.id}/encerrar`,
            { descricao: closeDescricao },
            {
                preserveScroll: true,
                onFinish: () => setCloseSubmitting(false),
                onSuccess: closeDrawer,
                onError: (errors) => {
                    if (errors.descricao) {
                        setCloseDescricaoError(errors.descricao);
                    }
                },
            },
        );
    };

    const openDelete = () => {
        setDeleteCode(generateConfirmationCode());
        setDeleteInput('');
        setDeleteOpen(true);
    };

    const closeDeleteDialog = () => {
        setDeleteOpen(false);
        setDeleteCode('');
        setDeleteInput('');
    };

    const confirmDelete = () => {
        if (deleteInput !== deleteCode) {
            return;
        }

        setDeleteSubmitting(true);
        router.delete(`/demandas/${demand.id}`, {
            onFinish: () => setDeleteSubmitting(false),
        });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Mais ações"
                    >
                        <MenuDotsIcon />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {plainTransitions.map((item) => (
                        <DropdownMenuItem
                            key={item.value}
                            onClick={() => moveTo(item.value)}
                        >
                            <ArrowRightIcon />
                            Mover para {item.label}
                        </DropdownMenuItem>
                    ))}
                    {plainTransitions.length > 0 && <DropdownMenuSeparator />}
                    {canResolve && (
                        <DropdownMenuItem onClick={() => setDialog('resolve')}>
                            <ChecklistIcon />
                            Marcar como resolvida
                        </DropdownMenuItem>
                    )}
                    {canClose && (
                        <DropdownMenuItem onClick={() => setCloseOpen(true)}>
                            <LockKeyholeIcon />
                            Encerrar
                        </DropdownMenuItem>
                    )}
                    {canReopen && (
                        <DropdownMenuItem onClick={() => setDialog('reopen')}>
                            <RestartIcon />
                            Reabrir
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem asChild>
                        <Link href={`/demandas/${demand.id}/edit`}>
                            <PenIcon />
                            Editar dados
                        </Link>
                    </DropdownMenuItem>
                    {canDelete && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onClick={openDelete}
                            >
                                <TrashBinTrashIcon />
                                Excluir
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => !open && close()}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {dialog === 'resolve' && 'Marcar como resolvida'}
                            {dialog === 'reopen' && 'Reabrir demanda'}
                        </DialogTitle>
                        <DialogDescription>
                            {dialog === 'resolve' &&
                                'O trabalho foi concluído. Resultado e descrição são opcionais.'}
                            {dialog === 'reopen' &&
                                'A demanda volta para Em andamento e o histórico é preservado.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        {dialog === 'resolve' && (
                            <div className="space-y-1">
                                <Label htmlFor="resultado">
                                    Resultado (opcional)
                                </Label>
                                <AppSelect
                                    id="resultado"
                                    value={resultado}
                                    onValueChange={setResultado}
                                    options={resultados}
                                    emptyLabel="Não informar"
                                />
                            </div>
                        )}
                        <div className="space-y-1">
                            <Label htmlFor="descricao-dialog">
                                {dialog === 'reopen' ? (
                                    <>
                                        Motivo <span aria-hidden="true">*</span>
                                    </>
                                ) : (
                                    'Descrição final (opcional)'
                                )}
                            </Label>
                            <Textarea
                                id="descricao-dialog"
                                rows={3}
                                value={descricao}
                                aria-invalid={Boolean(descricaoError)}
                                onChange={(event) => {
                                    setDescricao(event.target.value);
                                    setDescricaoError('');
                                }}
                            />
                            <FieldError message={descricaoError} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={close}>
                            Cancelar
                        </Button>
                        <Button onClick={confirm} disabled={submitting}>
                            Confirmar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Drawer
                open={closeOpen}
                onOpenChange={(open) => !open && closeDrawer()}
                swipeDirection="right"
            >
                <DrawerContent side="right">
                    <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                        <DrawerTitle className="text-xs font-semibold tracking-wide uppercase">
                            Encerrar
                        </DrawerTitle>
                        <DrawerClose
                            render={<Button variant="ghost" size="icon-sm" />}
                            aria-label="Fechar"
                        >
                            <CloseIcon aria-hidden="true" />
                        </DrawerClose>
                    </DrawerHeader>
                    <div className="flex min-h-0 flex-1 flex-col">
                        <div className="min-h-0 flex-1 space-y-1 overflow-y-auto p-5">
                            <Label htmlFor="descricao-encerrar">
                                Descrição final{' '}
                                <span aria-hidden="true">*</span>
                            </Label>
                            <Textarea
                                id="descricao-encerrar"
                                rows={4}
                                value={closeDescricao}
                                aria-invalid={Boolean(closeDescricaoError)}
                                onChange={(event) => {
                                    setCloseDescricao(event.target.value);
                                    setCloseDescricaoError('');
                                }}
                            />
                            <FieldError message={closeDescricaoError} />
                        </div>
                        <div className="flex shrink-0 justify-end gap-2 border-t p-4">
                            <Button variant="ghost" onClick={closeDrawer}>
                                Cancelar
                            </Button>
                            <Button
                                onClick={confirmClose}
                                disabled={closeSubmitting}
                            >
                                Encerrar
                            </Button>
                        </div>
                    </div>
                </DrawerContent>
            </Drawer>

            <Dialog
                open={deleteOpen}
                onOpenChange={(open) => !open && closeDeleteDialog()}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Excluir a Demanda {demand.protocolo}?
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            Para confirmar a exclusão, digite o código{' '}
                            <span className="font-mono text-sm font-semibold tracking-widest text-foreground select-all">
                                {deleteCode}
                            </span>{' '}
                            nos campos abaixo.
                        </p>
                        <div>
                            <InputOTP
                                id="delete-confirmation-code"
                                aria-label="Código de confirmação"
                                maxLength={6}
                                pattern={REGEXP_ONLY_DIGITS}
                                value={deleteInput}
                                onChange={setDeleteInput}
                                disabled={deleteSubmitting}
                                containerClassName="w-full justify-center"
                            >
                                <InputOTPGroup>
                                    {Array.from({ length: 6 }, (_, index) => (
                                        <InputOTPSlot
                                            key={index}
                                            index={index}
                                            className="rounded-md border"
                                        />
                                    ))}
                                </InputOTPGroup>
                            </InputOTP>
                        </div>
                        <DialogDescription>
                            O histórico permanece armazenado para auditoria, mas
                            a demanda some das listagens.
                        </DialogDescription>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={closeDeleteDialog}>
                            Cancelar
                        </Button>
                        <Button
                            variant="destructive-solid"
                            onClick={confirmDelete}
                            disabled={
                                deleteSubmitting || deleteInput !== deleteCode
                            }
                        >
                            Excluir
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
