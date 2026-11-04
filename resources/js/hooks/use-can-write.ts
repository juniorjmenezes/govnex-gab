import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

/**
 * Pode criar e alterar registros do gabinete (pelo cargo local; auditor e
 * quem não tem cargo nunca). Só esconde controles: o servidor
 * (`ResolveEntityContext` + Policies via `OfficeAccess`) segue como autoridade.
 */
export function useCanWrite(): boolean {
    const { auth } = usePage<{ auth: Auth }>().props;

    return auth.permissions?.writeRecords ?? false;
}
