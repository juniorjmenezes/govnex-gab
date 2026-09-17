import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    CloseIcon,
    EyeIcon,
    LibraryIcon,
    MagnifierIcon,
} from '@/components/icons';
import { KnowledgeReaderDialog } from '@/components/knowledge/knowledge-reader-dialog';
import { KnowledgeUploadForm } from '@/components/knowledge/knowledge-upload-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { formatFileSize, knowledgeBaseUrl } from '@/lib/knowledge';
import { preservedListParams } from '@/lib/pagination';
import type { KnowledgeDocument, KnowledgeIndexProps } from '@/types';

const dateFormatter = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

export default function KnowledgeIndex({
    scope,
    documents,
    filters,
    canUpload,
    maxUploadMb,
}: KnowledgeIndexProps) {
    const tenantUrl = useTenantUrl();
    const baseUrl = knowledgeBaseUrl(scope, tenantUrl);
    const isPlatform = scope === 'plataforma';
    const [query, setQuery] = useState(filters.q);
    const [selectedDocument, setSelectedDocument] = useState<KnowledgeDocument | null>(null);
    const [openingId, setOpeningId] = useState<number | null>(null);
    const isFirstRender = useRef(true);

    const openDocument = async (document: KnowledgeDocument) => {
        setOpeningId(document.id);

        try {
            const response = await fetch(`${baseUrl}/${document.id}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Falha ao carregar documento.');
            }

            const data: { document: KnowledgeDocument } = await response.json();
            setSelectedDocument(data.document);
        } finally {
            setOpeningId(null);
        }
    };

    const closeDocument = () => {
        setSelectedDocument(null);
        // O progresso pode ter mudado durante a leitura.
        router.reload({ only: ['documents'] });
    };

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                baseUrl,
                { ...(query && { q: query }), ...preservedListParams() },
                { preserveState: true, replace: true },
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [query, baseUrl]);

    return (
        <>
            <Head title="Base de Conhecimento" />
            <PageContainer>
                <PageHeader
                    title={
                        isPlatform
                            ? 'Base de Conhecimento da plataforma'
                            : 'Base de Conhecimento'
                    }
                    description={
                        isPlatform
                            ? 'PDFs disponíveis para leitura em todos os gabinetes com o módulo ativo.'
                            : 'Biblioteca de PDFs para leitura da equipe, com os documentos da plataforma e os do gabinete.'
                    }
                />

                {canUpload && (
                    <KnowledgeUploadForm
                        url={baseUrl}
                        maxUploadMb={maxUploadMb}
                        title={
                            isPlatform
                                ? 'Novo documento da plataforma'
                                : 'Novo documento do gabinete'
                        }
                    />
                )}

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader
                        actions={
                            <div className="flex w-full items-center gap-2 sm:w-72">
                                <div className="relative min-w-0 flex-1">
                                    <MagnifierIcon
                                        className="absolute top-2.5 left-3 size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        className="pl-9"
                                        placeholder="Buscar documento"
                                        aria-label="Buscar documento"
                                    />
                                </div>
                                {query && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        onClick={() => setQuery('')}
                                        aria-label="Limpar busca"
                                    >
                                        <CloseIcon />
                                    </Button>
                                )}
                            </div>
                        }
                    >
                        <SurfaceTitle>Documentos</SurfaceTitle>
                        <SurfaceDescription>
                            {documents.total === 1
                                ? '1 documento'
                                : `${documents.total} documentos`}
                        </SurfaceDescription>
                    </SurfaceHeader>

                    {documents.data.length === 0 ? (
                        <EmptyState
                            icon={LibraryIcon}
                            title="Nenhum documento encontrado"
                            description={
                                filters.q
                                    ? 'Ajuste os termos da busca.'
                                    : 'Adicione o primeiro PDF para começar a biblioteca.'
                            }
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-2/5">
                                            Documento
                                        </TableHead>
                                        {!isPlatform && (
                                            <TableHead>Origem</TableHead>
                                        )}
                                        <TableHead>Seu progresso</TableHead>
                                        <TableHead className="text-right">
                                            Leituras completas
                                        </TableHead>
                                        <TableHead>Enviado</TableHead>
                                        <TableHead className="w-px text-right">
                                            Ações
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {documents.data.map((document) => (
                                        <DocumentRow
                                            key={document.id}
                                            document={document}
                                            baseUrl={baseUrl}
                                            showOrigin={!isPlatform}
                                            onOpen={openDocument}
                                            isLoading={openingId === document.id}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                            <PaginationLinks
                                pagination={documents}
                                label="documento(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>

            <KnowledgeReaderDialog
                document={selectedDocument}
                fileUrl={selectedDocument ? `${baseUrl}/${selectedDocument.id}/arquivo` : null}
                progressUrl={selectedDocument ? `${baseUrl}/${selectedDocument.id}/leitura` : null}
                onClose={closeDocument}
            />
        </>
    );
}

function DocumentRow({
    document,
    baseUrl,
    showOrigin,
    onOpen,
    isLoading,
}: {
    document: KnowledgeDocument;
    baseUrl: string;
    showOrigin: boolean;
    onOpen: (document: KnowledgeDocument) => void;
    isLoading: boolean;
}) {
    const { progress } = document;
    const started = progress.pages_read.length > 0;
    const details = [
        document.total_pages ? `${document.total_pages} páginas` : null,
        formatFileSize(document.size),
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <TableRow>
            <TableCell className="whitespace-normal">
                <button
                    type="button"
                    onClick={() => onOpen(document)}
                    disabled={isLoading}
                    className="font-medium hover:underline text-left disabled:opacity-50"
                >
                    {document.title}
                </button>
                <p className="line-clamp-1 text-xs text-muted-foreground">
                    {document.description
                        ? `${document.description} · ${details}`
                        : details}
                </p>
            </TableCell>
            {showOrigin && (
                <TableCell>
                    <Badge variant="outline">
                        {document.origin === 'plataforma'
                            ? 'Plataforma'
                            : 'Gabinete'}
                    </Badge>
                </TableCell>
            )}
            <TableCell className="min-w-40">
                {progress.completed_at ? (
                    <Badge>Concluída</Badge>
                ) : started ? (
                    <div className="space-y-1">
                        <p className="text-xs text-muted-foreground tabular-nums">
                            {progress.percent}% lido
                        </p>
                        <Progress
                            value={progress.percent}
                            aria-label={`Progresso de ${document.title}`}
                        />
                    </div>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Não iniciada
                    </span>
                )}
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {document.completed_readings}
            </TableCell>
            <TableCell className="whitespace-normal">
                <p className="text-sm">{document.uploaded_by ?? '—'}</p>
                <p className="text-xs text-muted-foreground">
                    {document.created_at
                        ? dateFormatter.format(new Date(document.created_at))
                        : ''}
                </p>
            </TableCell>
            <TableCell className="w-px">
                <div className="flex justify-end gap-2">
                    <TableActionButton
                        label={`Ler ${document.title}`}
                        onClick={() => onOpen(document)}
                        disabled={isLoading}
                    >
                        <EyeIcon aria-hidden="true" />
                    </TableActionButton>
                    {document.can_delete && (
                        <DeleteRecordButton
                            url={`${baseUrl}/${document.id}`}
                            label={`Excluir ${document.title}`}
                            title="Excluir documento?"
                            description="O PDF sai da biblioteca e o progresso de leitura deixa de ser exibido."
                            subject={document.title}
                            subjectDetail={details}
                        />
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

KnowledgeIndex.layout = (page: KnowledgeIndexProps) => ({
    breadcrumbs:
        page.scope === 'plataforma'
            ? [
                  { title: 'Administração', href: '/dashboard' },
                  {
                      title: 'Base de Conhecimento',
                      href: '/admin/conhecimento',
                  },
              ]
            : [{ title: 'Base de Conhecimento', href: '/conhecimento' }],
});
