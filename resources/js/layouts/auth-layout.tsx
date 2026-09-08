import { usePage } from '@inertiajs/react';
import AuthCardLayoutTemplate from '@/layouts/auth/auth-card-layout';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { component } = usePage();
    const Template =
        component === 'auth/login'
            ? AuthCardLayoutTemplate
            : AuthLayoutTemplate;

    return (
        <Template title={title} description={description}>
            {children}
        </Template>
    );
}
