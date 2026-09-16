import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { AttachmentField } from '@/components/forms/attachment-field';
import { FieldError } from '@/components/forms/field-error';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import {
    DEMAND_ATTACHMENT_ACCEPT,
    DEMAND_ATTACHMENT_EXTENSIONS,
} from '@/lib/demand-attachments';

const schema = z.object({
    texto: z.string().min(1, 'Descreva o que foi feito.'),
});

type Values = z.infer<typeof schema>;

/** "Adicionar atualização": texto livre + anexos opcionais, sempre interno. */
export function UpdateForm({
    demandId,
    onDone,
}: {
    demandId: number;
    onDone?: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const {
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { texto: '' },
    });

    const tenantUrl = useTenantUrl();
    const submit = (values: Values) => {
        const data = new FormData();
        data.append('texto', values.texto);
        files.forEach((file) => data.append('arquivos[]', file));
        router.post(tenantUrl(`/demandas/${demandId}/atualizacoes`), data, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setFiles([]);
                onDone?.();
            },
            onError: (serverErrors) =>
                Object.entries(serverErrors).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });
    };

    return (
        <form
            noValidate
            onSubmit={handleSubmit(submit)}
            className="flex h-full min-h-0 flex-col"
        >
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                <div className="space-y-1">
                    <Textarea
                        rows={4}
                        placeholder="O que foi feito ou observado?"
                        aria-invalid={Boolean(errors.texto)}
                        {...register('texto')}
                    />
                    <FieldError message={errors.texto?.message} />
                </div>
                <AttachmentField
                    files={files}
                    onFilesChange={setFiles}
                    accept={DEMAND_ATTACHMENT_ACCEPT}
                    allowedExtensions={DEMAND_ATTACHMENT_EXTENSIONS}
                    allowCamera
                    showDropzone={false}
                    selectLabel="Anexar arquivos"
                />
            </div>
            <div className="flex shrink-0 justify-end gap-2 border-t p-4">
                {onDone && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancelar
                    </Button>
                )}
                <Button type="submit" disabled={isSubmitting}>
                    Adicionar atualização
                </Button>
            </div>
        </form>
    );
}
