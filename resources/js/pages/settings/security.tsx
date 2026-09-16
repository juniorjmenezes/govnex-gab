import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import { FieldError } from '@/components/forms/field-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { checkRequiredFormFields } from '@/lib/required-fields';
import type { InertiaFormRef } from '@/lib/required-fields';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const formRef = useRef<InertiaFormRef>(null);

    return (
        <>
            <Head title="Segurança" />

            <Card className="gap-0 py-0">
                <SurfaceHeader help="Use uma senha longa e exclusiva para manter sua conta protegida.">
                    <SurfaceTitle>Alterar senha</SurfaceTitle>
                </SurfaceHeader>
                <Form
                    noValidate
                    {...SecurityController.update.form()}
                    ref={formRef}
                    onBefore={() =>
                        checkRequiredFormFields(formRef.current, {
                            current_password: 'Informe a senha atual.',
                            password: 'Informe a nova senha.',
                            password_confirmation: 'Confirme a nova senha.',
                        })
                    }
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-5 p-5 md:grid-cols-3">
                                <div className="space-y-1">
                                    <Label htmlFor="current_password">
                                        Senha atual{' '}
                                        <span aria-hidden="true">*</span>
                                    </Label>
                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        autoComplete="current-password"
                                        placeholder="Senha atual"
                                        aria-required="true"
                                    />
                                    <FieldError
                                        message={errors.current_password}
                                    />
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="password">
                                        Nova senha{' '}
                                        <span aria-hidden="true">*</span>
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        autoComplete="new-password"
                                        placeholder="Nova senha"
                                        aria-required="true"
                                        passwordrules={props.passwordRules}
                                    />
                                    <FieldError message={errors.password} />
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="password_confirmation">
                                        Confirmar nova senha{' '}
                                        <span aria-hidden="true">*</span>
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        placeholder="Confirmar nova senha"
                                        aria-required="true"
                                        passwordrules={props.passwordRules}
                                    />
                                    <FieldError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 border-t p-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Salvar senha
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Card>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Segurança',
            href: edit(),
        },
    ],
};
