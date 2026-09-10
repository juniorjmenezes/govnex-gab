/**
 * Parâmetros de listagem que precisam sobreviver a uma nova busca ou filtro.
 * A barra de paginação grava `per_page` na URL; sem reinjetá-lo, qualquer
 * digitação no campo de busca remonta a query do zero e a escolha do usuário
 * volta silenciosamente ao padrão da listagem.
 */
export function preservedListParams(): Record<string, string> {
    if (typeof window === 'undefined') {
        return {};
    }

    const perPage = new URLSearchParams(window.location.search).get('per_page');

    return perPage ? { per_page: perPage } : {};
}
