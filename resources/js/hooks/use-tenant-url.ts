import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import { withTenantBase } from '@/lib/entity-context';
import type { Auth } from '@/types';

/**
 * Devolve um prefixador para as rotas de gabinete. As rotas de tenant são
 * registradas duas vezes em `routes/tenant.php`: a forma canônica
 * (`/entidades/{entidade}/gabinetes/{gabinete}/...`) e a legada, que só
 * existe como ponte e responde com um redirect auditado. Navegar pela forma
 * legada custa uma ida e volta extra, grava uma linha em
 * `contexto_acesso_eventos` a cada request e faz o destaque de item ativo
 * falhar (a URL final nunca bate com o href). Use este hook para montar
 * qualquer caminho de tenant no cliente.
 *
 * Fora de um contexto de gabinete (ex.: root na visão da plataforma) o
 * caminho volta inalterado, preservando o comportamento legado.
 */
export function useTenantUrl(): (path: string) => string {
    const { auth } = usePage<{ auth: Auth }>().props;
    const baseUrl = auth.context.gabinete_base_url;

    // Memoizado pela base do contexto (e não pelo objeto `auth`, que muda a
    // cada navegação) para poder entrar na lista de dependências de efeitos
    // sem forçar re-execução a cada render.
    return useCallback(
        (path: string) => withTenantBase(baseUrl, path),
        [baseUrl],
    );
}
