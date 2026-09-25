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
            /** Tela de estrutura do Govnex Hub (só para root, quando `HUB_BASE_URL` está configurada). */
            hubStructureUrl: string | null;
            /** Endereço-base do Govnex Hub, para apontar telas somente leitura (equipe, convites) — `null` sem `HUB_BASE_URL`. */
            hubBaseUrl: string | null;
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
