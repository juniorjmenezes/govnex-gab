import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            notifications: {
                unread_count: number;
                items: Array<{
                    id: string;
                    title: string;
                    message: string;
                    url: string | null;
                    read_at: string | null;
                    created_at: string | null;
                }>;
            };
            [key: string]: unknown;
        };
    }
}
