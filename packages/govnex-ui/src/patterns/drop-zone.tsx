import type { DragEvent, KeyboardEvent, PropsWithChildren } from 'react';
import { useState } from 'react';

import { cn } from '../lib/utils';

interface DropZoneProps {
    onFiles: (files: FileList) => void;
    onClick: () => void;
    disabled?: boolean;
    className?: string;
}

/**
 * Área de arrastar-e-soltar com destaque visual durante o arraste — a
 * superfície de base reutilizada por todo campo de upload da aplicação
 * (ver `AttachmentField`, em patterns/attachment-field.tsx). Clique ou
 * arraste disparam `onFiles`/`onClick`; o conteúdo (prévia, ícone, texto)
 * fica a cargo de quem usa o componente.
 */
export function DropZone({
    onFiles,
    onClick,
    disabled = false,
    className,
    children,
}: PropsWithChildren<DropZoneProps>) {
    const [isDragging, setIsDragging] = useState(false);

    return (
        <div
            role="button"
            tabIndex={disabled ? -1 : 0}
            aria-disabled={disabled}
            onClick={() => {
                if (!disabled) {
                    onClick();
                }
            }}
            onKeyDown={(event: KeyboardEvent<HTMLDivElement>) => {
                if (disabled) {
                    return;
                }

                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onClick();
                }
            }}
            onDragEnter={(event: DragEvent<HTMLDivElement>) => {
                event.preventDefault();

                if (!disabled) {
                    setIsDragging(true);
                }
            }}
            onDragOver={(event: DragEvent<HTMLDivElement>) =>
                event.preventDefault()
            }
            onDragLeave={() => setIsDragging(false)}
            onDrop={(event: DragEvent<HTMLDivElement>) => {
                event.preventDefault();
                setIsDragging(false);

                if (!disabled && event.dataTransfer.files.length > 0) {
                    onFiles(event.dataTransfer.files);
                }
            }}
            className={cn(
                'flex min-h-32 items-center justify-center rounded-lg border border-dashed bg-background p-4 transition-colors',
                !disabled && 'cursor-pointer',
                isDragging && 'border-primary bg-primary/5',
                disabled && 'pointer-events-none opacity-50',
                className,
            )}
        >
            {children}
        </div>
    );
}
