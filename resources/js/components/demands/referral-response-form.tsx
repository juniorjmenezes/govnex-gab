import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { AttachmentField } from '@/components/forms/attachment-field';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import {
    DEMAND_ATTACHMENT_ACCEPT,
    DEMAND_ATTACHMENT_EXTENSIONS,
} from '@/lib/demand-attachments';
import type { PendingReferral } from '@/types';

const schema = z.object({
    descricao: z.string().min(1, 'Descreva o retorno recebido.'),
    encaminhamento_id: z.string(),
});

type Values = z.infer<typeof schema>;

/**
 * "Registrar retorno" é uma ação independente, não uma resposta a um
 * encaminhamento específico — referenciar um encaminhamento é opcional e só
 * fecha o prazo esperado dele. Nunca altera o status da demanda sozinho.
 */
export function ReferralResponseForm({
    demandId,
    pendingReferrals,
    onDone,
}: {
    demandId: number;
    pendingReferrals: PendingReferral[];
    onDone?: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const {
        control,
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { descricao: '', encaminhamento_id: '' },
    });

    const tenantUrl = useTenantUrl();
    const submit = (values: Values) => {
        const data = new FormData();
        data.append('descricao', values.descricao);

        if (values.encaminhamento_id) {
            data.append('encaminhamento_id', values.encaminhamento_id);
        }

        files.forEach((file) => data.append('arquivos[]', file));
        router.post(tenantUrl(`/demandas/${demandId}/retornos`), data, {
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
                {pendingReferrals.length > 0 && (
                    <div className="space-y-1">
                        <Label htmlFor="encaminhamento_id">
                            Em resposta a (opcional)
                        </Label>
                        <Controller
                            control={control}
                            name="encaminhamento_id"
                            render={({ field }) => (
                                <AppSelect
                                    id="encaminhamento_id"
                                    value={field.value}
                                    onValueChange={field.onChange}
                                    emptyLabel="Nenhum encaminhamento específico"
                                    options={pendingReferrals.map((item) => ({
                                        value: item.id.toString(),
                                        label: item.destino ?? `#${item.id}`,
                                    }))}
                                />
                            )}
                        />
                    </div>
                )}
                <div className="space-y-1">
                    <Label htmlFor="descricao">
                        Retorno recebido <span aria-hidden="true">*</span>
                    </Label>
                    <Textarea
                        id="descricao"
                        rows={4}
                        aria-invalid={Boolean(errors.descricao)}
                        {...register('descricao')}
                    />
                    <FieldError message={errors.descricao?.message} />
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
                    Registrar retorno
                </Button>
            </div>
        </form>
    );
}
