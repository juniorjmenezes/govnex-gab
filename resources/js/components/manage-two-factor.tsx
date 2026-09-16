import { Form } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ShieldCheckIcon } from '@/components/icons';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <>
            <Card className="gap-0 py-0">
                <SurfaceHeader
                    actions={
                        <Badge
                            variant={twoFactorEnabled ? 'default' : 'secondary'}
                        >
                            {twoFactorEnabled ? 'Ativa' : 'Desativada'}
                        </Badge>
                    }
                >
                    <SurfaceTitle>Autenticação em dois fatores</SurfaceTitle>
                    <SurfaceDescription>
                        Segunda camada de proteção
                    </SurfaceDescription>
                </SurfaceHeader>
                <p className="p-5 text-sm text-muted-foreground">
                    {twoFactorEnabled
                        ? 'Durante o acesso, será solicitado um código gerado pelo aplicativo autenticador do seu celular.'
                        : 'Ao ativar, além da senha você informará um código gerado por um aplicativo autenticador.'}
                </p>
                <div className="flex justify-end gap-2 border-t p-4">
                    {twoFactorEnabled ? (
                        <Form {...disable.form()}>
                            {({ processing }) => (
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    disabled={processing}
                                >
                                    Desativar 2FA
                                </Button>
                            )}
                        </Form>
                    ) : hasSetupData ? (
                        <Button onClick={() => setShowSetupModal(true)}>
                            <ShieldCheckIcon />
                            Continuar configuração
                        </Button>
                    ) : (
                        <Form
                            {...enable.form()}
                            onSuccess={() => setShowSetupModal(true)}
                        >
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    <ShieldCheckIcon />
                                    Ativar 2FA
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </Card>

            {twoFactorEnabled && (
                <TwoFactorRecoveryCodes
                    recoveryCodesList={recoveryCodesList}
                    fetchRecoveryCodes={fetchRecoveryCodes}
                    errors={errors}
                />
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </>
    );
}
