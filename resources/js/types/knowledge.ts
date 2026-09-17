import type { Pagination } from './registrations';

/** `plataforma`: biblioteca do administrador; `gabinete`: da própria equipe. */
export type KnowledgeScope = 'plataforma' | 'gabinete';

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
    origin: KnowledgeScope;
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
    maxUploadMb: number;
};
