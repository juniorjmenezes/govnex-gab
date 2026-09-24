import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

/**
 * Perfil auditor é somente leitura. Só esconde controles: o servidor
 * (`ResolveEntidadeContext` + Policies `canWrite`) segue como autoridade.
 */
export function useCanWrite(): boolean {
    const { auth } = usePage<{ auth: Auth }>().props;

    return auth.user?.role !== 'auditor';
}
