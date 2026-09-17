import { Link, router } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { FieldError } from '@/components/forms/field-error';
import {
    ArrowRightIcon,
    ChecklistIcon,
    CloseIcon,
    LockKeyholeIcon,
    MenuDotsIcon,
    PenIcon,
    RestartIcon,
    TrashBinTrashIcon,
} from '@/components/icons';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
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
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { Demand, SelectOption } from '@/types';

/** Código de 6 dígitos que o usuário precisa digitar para confirmar a exclusão. */
const generateConfirmationCode = () =>
    String(Math.floor(100000 + Math.random() * 900000));

type DecisionKind = 'resolve' | 'reopen' | null;

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
    const tenantUrl = useTenantUrl();
    const [decision, setDecision] = useState<DecisionKind>(null);
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
            tenantUrl(`/demandas/${demand.id}/status`),
            { status },
            { preserveScroll: true },
        );
    };

    const close = () => {
        setDecision(null);
        setResultado('');
        setDescricao('');
        setDescricaoError('');
    };

    const confirm = () => {
        if (!decision) {
            return;
        }

        if (decision === 'reopen' && descricao.trim() === '') {
            setDescricaoError('Informe o motivo da reabertura.');

            return;
        }

        setDescricaoError('');
        setSubmitting(true);
        const url = tenantUrl(
            `/demandas/${demand.id}/${{ resolve: 'resolver', reopen: 'reabrir' }[decision]}`,
        );
        const payload =
            decision === 'resolve'
                ? { resultado: resultado || null, descricao: descricao || null }
                : { motivo: descricao };

        router.patch(url, payload, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
            onSuccess: close,
            onError: (errors) => {
                const message =
                    decision === 'reopen' ? errors.motivo : errors.descricao;

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
            tenantUrl(`/demandas/${demand.id}/encerrar`),
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

    const closeDeleteDrawer = () => {
        setDeleteOpen(false);
        setDeleteCode('');
        setDeleteInput('');
    };

    const confirmDelete = () => {
        if (deleteInput !== deleteCode) {
            return;
        }

        setDeleteSubmitting(true);
        router.delete(tenantUrl(`/demandas/${demand.id}`), {
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
                        <DropdownMenuItem
                            onClick={() => setDecision('resolve')}
                        >
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
                        <DropdownMenuItem onClick={() => setDecision('reopen')}>
                            <RestartIcon />
                            Reabrir
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem asChild>
                        <Link href={tenantUrl(`/demandas/${demand.id}/edit`)}>
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

            <DecisionDrawer
                open={decision !== null}
                onClose={close}
                title={
                    decision === 'reopen'
                        ? 'Reabrir demanda'
                        : 'Marcar como resolvida'
                }
                description={
                    decision === 'reopen'
                        ? 'A demanda volta para Em andamento e o histórico é preservado.'
                        : 'O trabalho foi concluído. Resultado e descrição são opcionais.'
                }
                confirmLabel="Confirmar"
                onConfirm={confirm}
                submitting={submitting}
            >
                {decision === 'resolve' && (
                    <div className="space-y-1">
                        <Label htmlFor="resultado">Resultado (opcional)</Label>
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
                    <Label htmlFor="descricao-decisao">
                        {decision === 'reopen' ? (
                            <>
                                Motivo <span aria-hidden="true">*</span>
                            </>
                        ) : (
                            'Descrição final (opcional)'
                        )}
                    </Label>
                    <Textarea
                        id="descricao-decisao"
                        rows={4}
                        value={descricao}
                        aria-invalid={Boolean(descricaoError)}
                        onChange={(event) => {
                            setDescricao(event.target.value);
                            setDescricaoError('');
                        }}
                    />
                    <FieldError message={descricaoError} />
                </div>
            </DecisionDrawer>

            <DecisionDrawer
                open={closeOpen}
                onClose={closeDrawer}
                title="Encerrar"
                description="O encerramento fecha a demanda sem registrar resultado."
                confirmLabel="Encerrar"
                onConfirm={confirmClose}
                submitting={closeSubmitting}
            >
                <div className="space-y-1">
                    <Label htmlFor="descricao-encerrar">
                        Descrição final <span aria-hidden="true">*</span>
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
            </DecisionDrawer>

            <DecisionDrawer
                open={deleteOpen}
                onClose={closeDeleteDrawer}
                title={`Excluir a Demanda ${demand.protocolo}?`}
                description="O histórico permanece armazenado para auditoria, mas a demanda some das listagens."
                confirmLabel="Excluir"
                confirmVariant="destructive-solid"
                onConfirm={confirmDelete}
                submitting={deleteSubmitting}
                confirmDisabled={deleteInput !== deleteCode}
            >
                <div className="rounded-md border bg-muted/40 px-3 py-2">
                    <p className="text-sm font-medium break-words">
                        {demand.titulo}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {[demand.protocolo, demand.cidadao?.nome]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                <p className="text-sm text-muted-foreground">
                    Para confirmar a exclusão, digite o código{' '}
                    <span className="text-sm font-semibold tracking-widest text-foreground tabular-nums select-all">
                        {deleteCode}
                    </span>{' '}
                    nos campos abaixo.
                </p>
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
            </DecisionDrawer>
        </>
    );
}

/**
 * Toda decisão de ciclo de vida da demanda (resolver, encerrar, reabrir,
 * excluir) abre neste drawer lateral: mesma moldura, mesmo lugar do botão de
 * confirmar, em vez de uma caixa no meio da tela.
 */
function DecisionDrawer({
    open,
    onClose,
    title,
    description,
    confirmLabel,
    confirmVariant = 'default',
    confirmDisabled = false,
    submitting,
    onConfirm,
    children,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    confirmLabel: string;
    confirmVariant?: ComponentProps<typeof Button>['variant'];
    confirmDisabled?: boolean;
    submitting: boolean;
    onConfirm: () => void;
    children: ReactNode;
}) {
    return (
        <Drawer
            open={open}
            onOpenChange={(next) => !next && onClose()}
            swipeDirection="right"
        >
            <DrawerContent side="right">
                <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                    <DrawerTitle>{title}</DrawerTitle>
                    <DrawerClose
                        render={<Button variant="ghost" size="icon-sm" />}
                        aria-label="Fechar"
                    >
                        <CloseIcon aria-hidden="true" />
                    </DrawerClose>
                </DrawerHeader>
                <div className="flex min-h-0 flex-1 flex-col">
                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                        {description && (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        )}
                        {children}
                    </div>
                    <div className="flex shrink-0 justify-end gap-2 border-t p-4">
                        <Button variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button
                            variant={confirmVariant}
                            onClick={onConfirm}
                            disabled={submitting || confirmDisabled}
                        >
                            {confirmLabel}
                        </Button>
                    </div>
                </div>
            </DrawerContent>
        </Drawer>
    );
}
