import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Encerrar minha conta"
                description="Remova permanentemente seu acesso ao sistema"
            />
            <div className="space-y-4 rounded-lg border border-destructive/20 bg-destructive/5 p-4 dark:border-destructive/30 dark:bg-destructive/10">
                <div className="relative space-y-0.5 text-destructive">
                    <p className="font-medium">Atenção</p>
                    <p className="text-sm">
                        Esta ação não pode ser desfeita. Registros
                        institucionais e históricos de auditoria serão
                        preservados.
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            Encerrar conta
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Encerrar sua conta?</DialogTitle>
                            <DialogDescription>
                                Você perderá imediatamente o acesso. Digite sua
                                senha atual para confirmar.
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{ preserveScroll: true }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            Senha atual
                                        </Label>
                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder="Senha atual"
                                            autoComplete="current-password"
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                Cancelar
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            variant="destructive-solid"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                Confirmar encerramento
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
