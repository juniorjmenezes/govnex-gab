import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

/**
 * Único template de autenticação hoje: coluna estreita e vertical (logo,
 * título, descrição, formulário), igual em todas as telas do fluxo —
 * inclusive o login, que até aqui era a exceção com o cartão largo em duas
 * colunas (`auth/auth-card-layout.tsx`, mantido no repositório mas sem uso
 * no momento).
 */
export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description}>
            {children}
        </AuthLayoutTemplate>
    );
}
