import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import { AddIcon } from '@/components/icons';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    onSuccess: () => void;
};

export default function PasskeyRegistration({ onSuccess }: Props) {
    const [name, setName] = useState(() => {
        const ua = navigator.userAgent;

        const browser = [
            { pattern: /Edg|Edge/, name: 'Edge' },
            { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
            { pattern: /Firefox|FxiOS/, name: 'Firefox' },
            { pattern: /Chrome|CriOS/, name: 'Chrome' },
            { pattern: /Safari/, name: 'Safari' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        const os = [
            { pattern: /iPhone/, name: 'iPhone' },
            { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
            { pattern: /Android/, name: 'Android' },
            { pattern: /Mac/, name: 'Mac' },
            { pattern: /Windows/, name: 'Windows' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        return [browser, os].filter(Boolean).join(' em ') || '';
    });

    const [showForm, setShowForm] = useState(false);
    const [nameError, setNameError] = useState('');
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            setNameError('Informe um nome para a chave de acesso.');

            return;
        }

        setNameError('');

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName('');
        setNameError('');
    };

    if (!isSupported) {
        return (
            <div className="text-sm text-muted-foreground">
                Este navegador não oferece suporte a chaves de acesso.
            </div>
        );
    }

    if (!showForm) {
        return (
            <div className="flex justify-end">
                <Button variant="outline" onClick={() => setShowForm(true)}>
                    <AddIcon aria-hidden="true" />
                    Adicionar chave de acesso
                </Button>
            </div>
        );
    }

    return (
        <form
            noValidate
            onSubmit={handleSubmit}
            className="flex flex-col gap-3 sm:flex-row sm:items-start"
        >
            <div className="min-w-0 flex-1 space-y-1">
                <Label htmlFor="passkey-name" className="sr-only">
                    Nome da chave de acesso
                </Label>
                <Input
                    id="passkey-name"
                    type="text"
                    value={name}
                    onChange={(e) => {
                        setName(e.target.value);
                        setNameError('');
                    }}
                    aria-required="true"
                    placeholder="Nome da chave (ex.: notebook pessoal, celular)"
                    autoFocus
                />
                <InputError message={nameError || error || undefined} />
            </div>

            <div className="flex shrink-0 gap-2">
                <Button type="button" variant="ghost" onClick={handleCancel}>
                    Cancelar
                </Button>
                <Button type="submit" disabled={isLoading}>
                    {isLoading ? 'Cadastrando...' : 'Cadastrar chave'}
                </Button>
            </div>
        </form>
    );
}
