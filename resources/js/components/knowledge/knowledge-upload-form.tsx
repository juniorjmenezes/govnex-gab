import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useRef } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { FieldError } from '@/components/forms/field-error';
import { AddIcon, PaperclipIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';

const isPdf = (file: File) =>
    file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

/**
 * Cadastro em linha no topo da biblioteca: título, descrição opcional e o
 * arquivo. Só PDFs são aceitos; o servidor confere de novo o conteúdo.
 */
export function KnowledgeUploadForm({
    url,
    maxUploadMb,
    title,
}: {
    url: string;
    maxUploadMb: number;
    title: string;
}) {
    const schema = z.object({
        titulo: z.string().trim().min(3, 'Informe o título do documento.'),
        descricao: z.string(),
        arquivo: z
            .custom<FileList>()
            .refine((files) => files?.length === 1, 'Selecione um arquivo PDF.')
            .refine(
                (files) => !files?.[0] || isPdf(files[0]),
                'Envie somente arquivos PDF.',
            )
            .refine(
                (files) =>
                    !files?.[0] || files[0].size <= maxUploadMb * 1024 * 1024,
                `O PDF pode ter no máximo ${maxUploadMb} MB.`,
            ),
    });
    type Values = z.infer<typeof schema>;

    const fileInput = useRef<HTMLInputElement>(null);
    const {
        register,
        handleSubmit,
        reset,
        setError,
        watch,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { titulo: '', descricao: '' },
    });
    const selectedFile = watch('arquivo')?.[0];
    const { ref: registerArquivoRef, ...arquivoField } = register('arquivo');

    const submit = (values: Values) =>
        new Promise<void>((resolve) => {
            router.post(
                url,
                {
                    titulo: values.titulo,
                    descricao: values.descricao || null,
                    arquivo: values.arquivo[0],
                },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        reset();

                        if (fileInput.current) {
                            fileInput.current.value = '';
                        }
                    },
                    onError: (items) =>
                        Object.entries(items).forEach(([key, message]) =>
                            setError(key as keyof Values, { message }),
                        ),
                    onFinish: () => resolve(),
                },
            );
        });

    return (
        <Surface as="section" className="overflow-hidden">
            <SurfaceHeader>
                <SurfaceTitle>{title}</SurfaceTitle>
                <SurfaceDescription>
                    Somente PDF, até {maxUploadMb} MB
                </SurfaceDescription>
            </SurfaceHeader>
            <form
                noValidate
                onSubmit={handleSubmit(submit)}
                className="flex flex-col gap-3 p-4 lg:flex-row lg:items-start"
            >
                <div className="w-full min-w-0 space-y-1 lg:flex-1">
                    <Input
                        aria-label="Título"
                        aria-required="true"
                        placeholder="Título do documento"
                        {...register('titulo')}
                    />
                    <FieldError message={errors.titulo?.message} />
                </div>
                <div className="w-full min-w-0 space-y-1 lg:flex-1">
                    <Input
                        aria-label="Descrição"
                        placeholder="Descrição (opcional)"
                        {...register('descricao')}
                    />
                    <FieldError message={errors.descricao?.message} />
                </div>
                <div className="w-full min-w-0 space-y-1 lg:w-72">
                    <input
                        type="file"
                        accept="application/pdf,.pdf"
                        aria-label="Arquivo PDF"
                        className="hidden"
                        {...arquivoField}
                        ref={(node) => {
                            registerArquivoRef(node);
                            fileInput.current = node;
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        aria-required="true"
                        className="w-full justify-start font-semibold"
                        onClick={() => fileInput.current?.click()}
                    >
                        <PaperclipIcon aria-hidden="true" />
                        <span className="truncate">
                            {selectedFile?.name ?? 'Selecionar arquivo PDF'}
                        </span>
                    </Button>
                    <FieldError message={errors.arquivo?.message} />
                </div>
                <Button
                    className="w-full shrink-0 lg:w-auto"
                    disabled={isSubmitting}
                >
                    <AddIcon aria-hidden="true" />
                    {isSubmitting ? 'Enviando...' : 'Adicionar PDF'}
                </Button>
            </form>
        </Surface>
    );
}
