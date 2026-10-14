import type { Pagination } from './registrations';

/** `plataforma`: biblioteca do administrador; `gabinete`: da própria equipe. */
export type KnowledgeScope = 'plataforma' | 'gabinete';

/**
 * De onde vem o documento: biblioteca da plataforma, compartilhado com a
 * entidade pela Secretaria legislativa ou do próprio gabinete.
 */
export type KnowledgeOrigin = 'plataforma' | 'entidade' | 'gabinete';

export type KnowledgeProgress = {
    pages_read: number[];
    last_page: number;
    percent: number;
    completed_at: string | null;
};

export type KnowledgeDocument = {
    id: number;
    title: string;
    description: string | null;
    origin: KnowledgeOrigin;
    /** Rótulo do selo quando `origin` é `entidade` (ex.: "Secretaria legislativa"). */
    origin_label: string | null;
    size: number;
    total_pages: number | null;
    completed_readings: number;
    uploaded_by: string | null;
    created_at: string | null;
    can_delete: boolean;
    progress: KnowledgeProgress;
};

export type KnowledgeIndexProps = {
    scope: KnowledgeScope;
    documents: Pagination<KnowledgeDocument>;
    filters: { q: string };
    canUpload: boolean;
    /** Só a Secretaria legislativa; ausente na biblioteca da plataforma. */
    canShareWithEntidade?: boolean;
    maxUploadMb: number;
};
