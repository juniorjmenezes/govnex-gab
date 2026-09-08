import { router } from '@inertiajs/react';
import { CloseIcon, DownloadIcon, FileIcon } from '@solar-icons/react/outline';
import { Button } from '@/components/ui/button';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { DemandAttachment } from '@/types';

const formatSize = (bytes: number) =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(2)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

/**
 * Mesmo padrão visual do AttachmentField (ícone em selo, nome truncado
 * preservando a extensão, tamanho embaixo) — para a lista de arquivos ficar
 * igual em toda a aplicação, seja anexando ou revendo o que já foi anexado.
 */
const truncateFileName = (name: string, maxLength = 28) => {
    if (name.length <= maxLength) {
        return name;
    }

    const dotIndex = name.lastIndexOf('.');
    const hasExtension = dotIndex > 0 && dotIndex < name.length - 1;
    const extension = hasExtension ? name.slice(dotIndex) : '';
    const base = hasExtension ? name.slice(0, dotIndex) : name;
    const keep = Math.max(1, maxLength - extension.length - 1);

    return `${base.slice(0, keep)}…${extension}`;
};

export function AttachmentList({
    demandId,
    attachments,
}: {
    demandId: number;
    attachments: DemandAttachment[];
}) {
    const tenantUrl = useTenantUrl();

    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul className="mt-3 grid gap-2">
            {attachments.map((attachment) => {
                const base = tenantUrl(
                    `/demandas/${demandId}/anexos/${attachment.id}`,
                );

                return (
                    <li
                        key={attachment.id}
                        className="flex min-w-0 items-center gap-3 rounded-md border p-3"
                    >
                        <span className="grid size-10 shrink-0 place-items-center rounded-sm bg-muted text-muted-foreground">
                            <FileIcon className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p
                                className="truncate text-xs font-medium"
                                title={attachment.nome_original}
                            >
                                {truncateFileName(attachment.nome_original)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {formatSize(attachment.tamanho)}
                            </p>
                        </div>
                        <Button
                            asChild
                            size="icon"
                            variant="ghost"
                            aria-label={`Baixar ${attachment.nome_original}`}
                        >
                            <a href={`${base}/download`}>
                                <DownloadIcon />
                            </a>
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label={`Remover ${attachment.nome_original}`}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        `Remover ${attachment.nome_original}?`,
                                    )
                                ) {
                                    router.delete(base, {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            <CloseIcon />
                        </Button>
                    </li>
                );
            })}
        </ul>
    );
}
