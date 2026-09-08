import type { Auth } from '@/types';

/**
 * Primeiro segmento das rotas registradas em `routes/tenant.php` (mais o
 * `dashboard` de `routes/web.php`, que também é por gabinete). Só esses
 * caminhos ganham o prefixo de contexto — `/admin`, `/entidades`,
 * `/settings`, `/login` e afins são globais e devem passar intactos.
 */
const TENANT_ROOTS = [
    'agenda',
    'atendimentos',
    'bairros',
    'categorias',
    'cidadaos',
    'configuracoes',
    'dashboard',
    'demandas',
    'eleitores',
    'equipe',
    'eventos',
    'notificacoes',
    'painel-politico',
    'relatorios',
] as const;

function isTenantPath(path: string): boolean {
    const root = path.replace(/^\//, '').split(/[/?#]/, 1)[0];

    return (TENANT_ROOTS as readonly string[]).includes(root);
}

/**
 * Converte um caminho de gabinete na sua forma canônica
 * (`/entidades/{entidade}/gabinetes/{gabinete}/...`). A forma legada ainda
 * responde, mas só via redirect auditado: cada acesso custa uma ida e volta
 * extra, grava uma linha em `contexto_acesso_eventos` e quebra o destaque de
 * item ativo, porque a URL final nunca coincide com o href declarado.
 *
 * Sem contexto de gabinete (root na visão da plataforma), ou para caminhos
 * que não pertencem ao tenant, o valor volta inalterado.
 */
export function withTenantBase(
    baseUrl: string | null | undefined,
    path: string,
): string {
    if (!baseUrl) {
        return path;
    }

    const normalized = path.startsWith('/') ? path : `/${path}`;

    if (!isTenantPath(normalized)) {
        return path;
    }

    return `${baseUrl}${normalized}`;
}

export function contextualUrl(auth: Auth, path: string): string {
    return withTenantBase(auth.context.gabinete_base_url, path);
}
