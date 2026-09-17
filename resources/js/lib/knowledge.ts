import type { KnowledgeScope } from '@/types';

/**
 * Endereço base da Base de Conhecimento: a biblioteca da plataforma fica na
 * área administrativa; a do gabinete, no contexto dele.
 */
export function knowledgeBaseUrl(
    scope: KnowledgeScope,
    tenantUrl: (path: string) => string,
): string {
    return scope === 'plataforma'
        ? '/admin/conhecimento'
        : tenantUrl('/conhecimento');
}

export function formatFileSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toLocaleString('pt-BR', {
            maximumFractionDigits: 1,
        })} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
