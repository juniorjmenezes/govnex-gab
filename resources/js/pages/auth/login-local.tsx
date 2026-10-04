import { Head, Link } from '@inertiajs/react';
import { LocalLoginForm } from '@/components/local-login-form';
import { Button } from '@/components/ui/button';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function LoginLocal({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Acesso local de emergência" />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-emerald-700 dark:text-emerald-400">
                    {status}
                </div>
            )}

            <div className="space-y-4">
                <LocalLoginForm canResetPassword={canResetPassword} />

                <Button asChild variant="ghost" className="w-full">
                    <Link href="/login">Voltar para o login</Link>
                </Button>
            </div>
        </>
    );
}

LoginLocal.layout = {
    title: 'Acesso local de emergência',
    description:
        'Restrito à conta root, para quando o Govnex Hub estiver indisponível.',
};
