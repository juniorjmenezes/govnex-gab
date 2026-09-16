import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { checkRequiredFormFields } from '@/lib/required-fields';
import type { InertiaFormRef } from '@/lib/required-fields';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const formRef = useRef<InertiaFormRef>(null);

    return (
        <>
            <Head title="Confirmar senha" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Confirmar com chave de acesso"
                loadingLabel="Confirmando..."
                separator="Ou confirme com a senha"
            />

            <Form
                noValidate
                {...store.form()}
                ref={formRef}
                resetOnSuccess={['password']}
                onBefore={() =>
                    checkRequiredFormFields(formRef.current, {
                        password: 'Informe a senha.',
                    })
                }
            >
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">Senha</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Senha"
                                aria-required="true"
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Confirmar senha
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirmar senha',
    description:
        'Esta é uma área protegida. Confirme sua senha para continuar.',
};
