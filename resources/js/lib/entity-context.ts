import type { Auth } from '@/types';

export function contextualUrl(auth: Auth, path: string): string {
    const normalized = path.startsWith('/') ? path : `/${path}`;

    return auth.context.gabinete_base_url
        ? `${auth.context.gabinete_base_url}${normalized}`
        : normalized;
}
