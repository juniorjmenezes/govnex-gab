import { router } from '@inertiajs/react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import { EmptyState } from '@/components/feedback/empty-state';
import { KeyIcon } from '@/components/icons';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import { Card } from '@/components/ui/card';
import {
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <Card className="gap-0 py-0">
            <SurfaceHeader help="Chaves de acesso usam a biometria ou o PIN do dispositivo no lugar da senha.">
                <SurfaceTitle>Chaves de acesso</SurfaceTitle>
                <SurfaceDescription>
                    {passkeys.length === 0
                        ? 'Nenhuma cadastrada'
                        : passkeys.length === 1
                          ? '1 cadastrada'
                          : `${passkeys.length} cadastradas`}
                </SurfaceDescription>
            </SurfaceHeader>

            {passkeys.length > 0 ? (
                <div>
                    {passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))}
                </div>
            ) : (
                <EmptyState
                    icon={KeyIcon}
                    title="Nenhuma chave de acesso cadastrada"
                    description="Adicione uma chave para entrar sem digitar a senha."
                />
            )}

            <div className="border-t p-4">
                <PasskeyRegistration onSuccess={handleRegisterSuccess} />
            </div>
        </Card>
    );
}
