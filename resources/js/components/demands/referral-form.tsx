import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { AttachmentField } from '@/components/forms/attachment-field';
import { DatePicker } from '@/components/forms/date-picker';
import { FieldError } from '@/components/forms/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import {
    DEMAND_ATTACHMENT_ACCEPT,
    DEMAND_ATTACHMENT_EXTENSIONS,
} from '@/lib/demand-attachments';

const schema = z.object({
    destino: z.string().min(1, 'Informe para onde a demanda foi encaminhada.'),
    setor: z.string(),
    referencia_externa: z.string(),
    descricao: z.string(),
    prazo_esperado: z.string(),
});

type Values = z.infer<typeof schema>;

/**
 * Um encaminhamento vira um único evento de timeline — sem campo de
 * situação. "Registrar encaminhamento" é tudo que essa ação faz.
 */
export function ReferralForm({
    demandId,
    onDone,
}: {
    demandId: number;
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
        defaultValues: {
            destino: '',
            setor: '',
            referencia_externa: '',
            descricao: '',
            prazo_esperado: '',
        },
    });

    const tenantUrl = useTenantUrl();
    const submit = (values: Values) => {
        const data = new FormData();
        Object.entries(values).forEach(([key, value]) => {
            if (value) {
                data.append(key, value);
            }
        });
        files.forEach((file) => data.append('arquivos[]', file));
        router.post(tenantUrl(`/demandas/${demandId}/encaminhamentos`), data, {
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
            onSubmit={handleSubmit(submit)}
            className="flex h-full min-h-0 flex-col"
        >
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                <div className="space-y-1">
                    <Label htmlFor="destino">
                        Destino <span aria-hidden="true">*</span>
                    </Label>
                    <Input
                        id="destino"
                        placeholder="Órgão, secretaria ou pessoa"
                        aria-invalid={Boolean(errors.destino)}
                        {...register('destino')}
                    />
                    <FieldError message={errors.destino?.message} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="setor">Setor</Label>
                        <Input id="setor" {...register('setor')} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="referencia_externa">
                            Referência/documento
                        </Label>
                        <Input
                            id="referencia_externa"
                            {...register('referencia_externa')}
                        />
                    </div>
                </div>
                <div className="space-y-1">
                    <Label htmlFor="descricao">Descrição</Label>
                    <Textarea
                        id="descricao"
                        rows={3}
                        placeholder="O que foi solicitado ao destino"
                        {...register('descricao')}
                    />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="prazo_esperado">Prazo esperado</Label>
                    <Controller
                        control={control}
                        name="prazo_esperado"
                        render={({ field }) => (
                            <DatePicker
                                id="prazo_esperado"
                                value={field.value}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    <FieldError message={errors.prazo_esperado?.message} />
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
                    Registrar encaminhamento
                </Button>
            </div>
        </form>
    );
}
