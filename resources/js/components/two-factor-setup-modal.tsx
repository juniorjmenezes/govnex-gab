import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogFooter,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
import { FieldError } from '@/components/forms/field-error';
import { CheckCircleIcon, CopyIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAppearance } from '@/hooks/use-appearance';
import { useClipboard } from '@/hooks/use-clipboard';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { confirm } from '@/routes/two-factor';

const VERIFICATION_FORM_ID = 'two-factor-confirmation-form';

/**
 * Corpo da etapa de configuração: QR code e, como alternativa, a chave para
 * digitar no aplicativo autenticador.
 */
function TwoFactorSetupStep({
    qrCodeSvg,
    manualSetupKey,
    errors,
}: {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    errors: string[];
}) {
    const { resolvedAppearance } = useAppearance();
    const [copiedText, copy] = useClipboard();
    const copied = manualSetupKey !== null && copiedText === manualSetupKey;
    const CopyStateIcon = copied ? CheckCircleIcon : CopyIcon;

    if (errors?.length) {
        return <AlertError errors={errors} />;
    }

    return (
        <div className="grid gap-5 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-center">
            <div className="mx-auto flex aspect-square w-44 items-center justify-center rounded-md bg-white p-3 ring-1 ring-foreground/10">
                {qrCodeSvg ? (
                    <div
                        className="size-full [&_svg]:size-full"
                        aria-label="QR code para o aplicativo autenticador"
                        role="img"
                        dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                        style={{
                            filter:
                                resolvedAppearance === 'dark'
                                    ? 'invert(1) brightness(1.5)'
                                    : undefined,
                        }}
                    />
                ) : (
                    <Spinner />
                )}
            </div>

            <div className="space-y-4">
                <ol className="list-inside list-decimal space-y-1 text-sm text-muted-foreground">
                    <li>Abra o aplicativo autenticador no celular.</li>
                    <li>Leia o QR code ou informe a chave abaixo.</li>
                    <li>Continue para confirmar o código gerado.</li>
                </ol>

                <div className="space-y-1">
                    <Label htmlFor="two-factor-setup-key">
                        Chave de configuração
                    </Label>
                    <InputGroup>
                        <InputGroupInput
                            id="two-factor-setup-key"
                            readOnly
                            value={manualSetupKey ?? ''}
                            placeholder="Carregando…"
                            className="font-mono"
                        />
                        <InputGroupAddon align="inline-end">
                            <InputGroupButton
                                size="icon-xs"
                                disabled={!manualSetupKey}
                                aria-label={
                                    copied
                                        ? 'Chave copiada'
                                        : 'Copiar chave de configuração'
                                }
                                onClick={() =>
                                    manualSetupKey && copy(manualSetupKey)
                                }
                            >
                                <CopyStateIcon />
                            </InputGroupButton>
                        </InputGroupAddon>
                    </InputGroup>
                </div>
            </div>
        </div>
    );
}

/**
 * Corpo da etapa de confirmação. O envio fica no rodapé do dialog, ligado a
 * este formulário pelo atributo `form`.
 */
function TwoFactorVerificationStep({
    onSuccess,
    onProcessingChange,
}: {
    onSuccess: () => void;
    onProcessingChange: (processing: boolean) => void;
}) {
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState('');

    return (
        <Form
            noValidate
            id={VERIFICATION_FORM_ID}
            {...confirm.form()}
            onBefore={() => {
                if (code.length < OTP_MAX_LENGTH) {
                    setCodeError(
                        `Informe os ${OTP_MAX_LENGTH} dígitos do código.`,
                    );

                    return false;
                }

                setCodeError('');
            }}
            onStart={() => onProcessingChange(true)}
            onFinish={() => onProcessingChange(false)}
            onSuccess={onSuccess}
            onError={() => setCode('')}
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors?: { confirmTwoFactorAuthentication?: { code?: string } };
            }) => (
                <div className="flex flex-col items-center gap-2 py-2">
                    <Label htmlFor="two-factor-code" className="sr-only">
                        Código de autenticação
                    </Label>
                    <InputOTP
                        id="two-factor-code"
                        name="code"
                        maxLength={OTP_MAX_LENGTH}
                        value={code}
                        onChange={(value) => {
                            setCode(value);
                            setCodeError('');
                        }}
                        disabled={processing}
                        pattern={REGEXP_ONLY_DIGITS}
                        autoFocus
                    >
                        <InputOTPGroup>
                            {Array.from(
                                { length: OTP_MAX_LENGTH },
                                (_, index) => (
                                    <InputOTPSlot key={index} index={index} />
                                ),
                            )}
                        </InputOTPGroup>
                    </InputOTP>
                    <FieldError
                        message={
                            codeError ||
                            errors?.confirmTwoFactorAuthentication?.code
                        }
                    />
                </div>
            )}
        </Form>
    );
}

type Props = {
    isOpen: boolean;
    onClose: () => void;
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    clearSetupData: () => void;
    fetchSetupData: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorSetupModal({
    isOpen,
    onClose,
    requiresConfirmation,
    twoFactorEnabled,
    qrCodeSvg,
    manualSetupKey,
    clearSetupData,
    fetchSetupData,
    errors,
}: Props) {
    const [showVerificationStep, setShowVerificationStep] = useState(false);
    const [verifying, setVerifying] = useState(false);

    const modalConfig = useMemo(() => {
        if (twoFactorEnabled) {
            return {
                title: 'Autenticação em dois fatores ativada',
                description:
                    'Guarde a chave ou leia o QR code novamente no aplicativo autenticador, se precisar.',
            };
        }

        if (showVerificationStep) {
            return {
                title: 'Confirmar código de autenticação',
                description: `Informe o código de ${OTP_MAX_LENGTH} dígitos gerado pelo aplicativo autenticador.`,
            };
        }

        return {
            title: 'Ativar autenticação em dois fatores',
            description:
                'Vincule sua conta ao aplicativo autenticador do celular.',
        };
    }, [twoFactorEnabled, showVerificationStep]);

    const resetModalState = useCallback(() => {
        if (twoFactorEnabled) {
            clearSetupData();
        }

        setShowVerificationStep(false);
    }, [clearSetupData, twoFactorEnabled]);

    const handleClose = useCallback(() => {
        if (verifying) {
            return;
        }

        resetModalState();
        onClose();
    }, [onClose, resetModalState, verifying]);

    const handleModalNextStep = useCallback(() => {
        if (requiresConfirmation && !twoFactorEnabled) {
            setShowVerificationStep(true);

            return;
        }

        clearSetupData();
        handleClose();
    }, [requiresConfirmation, twoFactorEnabled, clearSetupData, handleClose]);

    const fetchSetupDataRef = useRef(fetchSetupData);

    useEffect(() => {
        fetchSetupDataRef.current = fetchSetupData;
    }, [fetchSetupData]);

    useEffect(() => {
        if (isOpen && !qrCodeSvg) {
            fetchSetupDataRef.current();
        }
    }, [isOpen, qrCodeSvg]);

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <ScrollableDialogContent className="sm:max-w-xl">
                <ScrollableDialogHeader>
                    <DialogTitle>{modalConfig.title}</DialogTitle>
                    <DialogDescription>
                        {modalConfig.description}
                    </DialogDescription>
                </ScrollableDialogHeader>

                <ScrollableDialogBody>
                    {showVerificationStep ? (
                        <TwoFactorVerificationStep
                            onSuccess={() => {
                                setVerifying(false);
                                resetModalState();
                                onClose();
                            }}
                            onProcessingChange={setVerifying}
                        />
                    ) : (
                        <TwoFactorSetupStep
                            qrCodeSvg={qrCodeSvg}
                            manualSetupKey={manualSetupKey}
                            errors={errors}
                        />
                    )}
                </ScrollableDialogBody>

                <ScrollableDialogFooter>
                    {showVerificationStep ? (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={verifying}
                                onClick={() => setShowVerificationStep(false)}
                            >
                                Voltar
                            </Button>
                            <Button
                                type="submit"
                                form={VERIFICATION_FORM_ID}
                                disabled={verifying}
                            >
                                {verifying && <Spinner />}
                                Confirmar
                            </Button>
                        </>
                    ) : twoFactorEnabled ? (
                        <Button type="button" onClick={handleModalNextStep}>
                            Fechar
                        </Button>
                    ) : (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleClose}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="button"
                                disabled={errors.length > 0}
                                onClick={handleModalNextStep}
                            >
                                Continuar
                            </Button>
                        </>
                    )}
                </ScrollableDialogFooter>
            </ScrollableDialogContent>
        </Dialog>
    );
}
