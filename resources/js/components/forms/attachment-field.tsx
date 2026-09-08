import {
    CameraIcon,
    CloseIcon,
    CloudUploadIcon,
    FileIcon,
    PaperclipIcon,
} from '@solar-icons/react/outline';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ChangeEvent, ReactNode } from 'react';
import { DropZone } from '@/components/forms/drop-zone';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type AttachmentCurrentFile = {
    name: string;
    url?: string | null;
    imagem?: boolean;
};

type AttachmentFieldProps = {
    /** Arquivos novos, ainda não enviados — o componente é sempre controlado. */
    files: File[];
    onFilesChange: (files: File[]) => void;
    /** Arquivo já salvo (ex.: logo atual), exibido até ser removido ou substituído. */
    current?: AttachmentCurrentFile | null;
    onRemoveCurrent?: () => void;
    /** @default 5 */
    maxFiles?: number;
    /** @default 10 */
    maxSizeMb?: number;
    /** Atributo `accept` do input nativo. */
    accept?: string;
    /** Extensões aceitas (sem ponto), usadas na validação e na mensagem de erro. */
    allowedExtensions?: string[];
    allowCamera?: boolean;
    disabled?: boolean;
    title?: string;
    helperText?: string;
    dropzoneLabel?: string;
    selectLabel?: string;
    /**
     * Ação extra renderizada ao lado do botão de seleção, no mesmo
     * agrupamento visual (sem espaçamento, bordas compartilhadas).
     */
    trailingAction?: ReactNode;
    error?: string;
    className?: string;
    /**
     * Em contextos já compactos (drawers), a área de arrastar-e-soltar só
     * alonga a tela sem necessidade — deixe `false` para exibir apenas os
     * botões de seleção.
     * @default true
     */
    showDropzone?: boolean;
};

const formatSize = (bytes: number) =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(2)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const extensionOf = (name: string) =>
    name.split('.').pop()?.toLowerCase() ?? '';

/**
 * O `truncate` do CSS não basta aqui: como o item da lista é filho de um
 * grid, ele herda `min-width: auto` e cresce até o tamanho do nome inteiro
 * antes de cortar — o que estoura a largura do drawer. Cortamos o texto na
 * marra, preservando a extensão, para o container nunca ultrapassar a
 * largura disponível.
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

/**
 * Campo de anexo padrão da aplicação: arrastar-e-soltar (via `DropZone`),
 * seleção tradicional, câmera opcional e uma lista dos arquivos escolhidos
 * com prévia, tamanho e remoção — o mesmo componente serve tanto para
 * anexar vários arquivos a um registro quanto para substituir um arquivo
 * único (logo, ZIP): quando `maxFiles` é 1, uma nova seleção substitui a
 * anterior em vez de exigir remoção manual.
 */
export function AttachmentField({
    files,
    onFilesChange,
    current,
    onRemoveCurrent,
    maxFiles = 5,
    maxSizeMb = 10,
    accept,
    allowedExtensions,
    allowCamera = false,
    disabled = false,
    title,
    helperText,
    dropzoneLabel = 'Arraste arquivos ou clique para selecionar',
    selectLabel = 'Selecionar',
    trailingAction,
    error,
    className,
    showDropzone = true,
}: AttachmentFieldProps) {
    const [localError, setLocalError] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);
    const cameraInput = useRef<HTMLInputElement>(null);
    const previews = useMemo(
        () =>
            files.map((file) =>
                file.type.startsWith('image/')
                    ? URL.createObjectURL(file)
                    : null,
            ),
        [files],
    );

    useEffect(
        () => () => previews.forEach((url) => url && URL.revokeObjectURL(url)),
        [previews],
    );

    const showCurrent = Boolean(current) && files.length === 0;
    const maxSizeBytes = maxSizeMb * 1024 * 1024;

    const addFiles = (incoming: File[]) => {
        if (incoming.length === 0) {
            return;
        }

        setLocalError('');

        const invalid = incoming.find(
            (file) =>
                file.size > maxSizeBytes ||
                (allowedExtensions &&
                    !allowedExtensions.includes(extensionOf(file.name))),
        );

        if (invalid) {
            setLocalError(
                invalid.size > maxSizeBytes
                    ? `${invalid.name} ultrapassa ${maxSizeMb} MB.`
                    : `${invalid.name} possui um formato não permitido.`,
            );

            return;
        }

        if (maxFiles === 1) {
            onFilesChange([incoming[0]]);

            return;
        }

        const merged = [...files, ...incoming];

        if (merged.length > maxFiles) {
            setLocalError(
                `Selecione no máximo ${maxFiles} arquivo${maxFiles === 1 ? '' : 's'} por vez.`,
            );

            return;
        }

        onFilesChange(merged);
    };

    const selectFiles = (event: ChangeEvent<HTMLInputElement>) => {
        addFiles(Array.from(event.target.files ?? []));
        event.target.value = '';
    };

    const removeAt = (index: number) => {
        onFilesChange(files.filter((_, itemIndex) => itemIndex !== index));
    };

    const atCapacity = maxFiles > 1 && files.length >= maxFiles;
    const message = localError || error;

    return (
        <div className={className}>
            {title && (
                <div className="mb-2">
                    <p className="text-sm font-medium">{title}</p>
                    {helperText && (
                        <p className="text-xs text-muted-foreground">
                            {helperText}
                        </p>
                    )}
                </div>
            )}

            <input
                ref={fileInput}
                type="file"
                multiple={maxFiles > 1}
                accept={accept}
                disabled={disabled}
                className="hidden"
                onChange={selectFiles}
            />
            {allowCamera && (
                <input
                    ref={cameraInput}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    disabled={disabled}
                    className="hidden"
                    onChange={selectFiles}
                />
            )}

            {showDropzone && (
                <DropZone
                    onClick={() => fileInput.current?.click()}
                    onFiles={(fileList) => addFiles(Array.from(fileList))}
                    disabled={disabled || atCapacity}
                >
                    <div className="flex flex-col items-center gap-1 text-center">
                        <CloudUploadIcon className="size-7 text-muted-foreground" />
                        <p className="text-sm font-medium">{dropzoneLabel}</p>
                        <p className="text-xs text-muted-foreground">
                            {maxFiles > 1
                                ? `Até ${maxFiles} arquivos, ${maxSizeMb} MB cada`
                                : `Até ${maxSizeMb} MB`}
                        </p>
                    </div>
                </DropZone>
            )}

            <div
                className={cn(
                    'flex w-full -space-x-px',
                    showDropzone && 'mt-3',
                )}
            >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={disabled || atCapacity}
                    onClick={() => fileInput.current?.click()}
                    className="flex-1 rounded-none first:rounded-l-md last:rounded-r-md focus-visible:z-10"
                >
                    <PaperclipIcon />
                    {selectLabel}
                </Button>
                {allowCamera && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={disabled || atCapacity}
                        onClick={() => cameraInput.current?.click()}
                        className="flex-1 rounded-none first:rounded-l-md last:rounded-r-md focus-visible:z-10"
                    >
                        <CameraIcon />
                        Usar câmera
                    </Button>
                )}
                {trailingAction}
            </div>

            {(showCurrent || files.length > 0) && (
                <ul className="mt-3 grid gap-2">
                    {showCurrent && current && (
                        <li className="flex min-w-0 items-center gap-3 rounded-md border p-3">
                            {current.imagem && current.url ? (
                                <img
                                    src={current.url}
                                    alt=""
                                    className="size-10 shrink-0 rounded object-cover"
                                />
                            ) : (
                                <span className="grid size-10 shrink-0 place-items-center rounded-sm bg-muted text-muted-foreground">
                                    <FileIcon
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                            )}
                            <div className="min-w-0 flex-1">
                                <p
                                    className="truncate text-xs font-medium"
                                    title={current.name}
                                >
                                    {truncateFileName(current.name)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Atual
                                </p>
                            </div>
                            {onRemoveCurrent && !disabled && (
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remover ${current.name}`}
                                    onClick={onRemoveCurrent}
                                >
                                    <CloseIcon />
                                </Button>
                            )}
                        </li>
                    )}
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${file.lastModified}-${index}`}
                            className="flex min-w-0 items-center gap-3 rounded-md border p-3"
                        >
                            {previews[index] ? (
                                <img
                                    src={previews[index] ?? ''}
                                    alt=""
                                    className="size-10 shrink-0 rounded object-cover"
                                />
                            ) : (
                                <span className="grid size-10 shrink-0 place-items-center rounded-sm bg-muted text-muted-foreground">
                                    <FileIcon
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                            )}
                            <div className="min-w-0 flex-1">
                                <p
                                    className="truncate text-xs font-medium"
                                    title={file.name}
                                >
                                    {truncateFileName(file.name)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatSize(file.size)}
                                </p>
                            </div>
                            {!disabled && (
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remover ${file.name}`}
                                    onClick={() => removeAt(index)}
                                >
                                    <CloseIcon />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {message && (
                <p className="mt-2 text-xs text-destructive">{message}</p>
            )}
        </div>
    );
}
