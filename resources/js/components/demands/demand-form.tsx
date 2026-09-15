import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import type { Control, FieldPath } from 'react-hook-form';
import { z } from 'zod';
import { CategoryIconBadge } from '@/components/categories/category-appearance';
import { AddressFields } from '@/components/forms/address-fields';
import { DatePicker } from '@/components/forms/date-picker';
import { FieldError } from '@/components/forms/field-error';
import { TimePicker } from '@/components/forms/time-picker';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { Demand, DemandOptions, OfficeLocation } from '@/types';

const schema = z
    .object({
        cidadao_id: z.string().min(1, 'Selecione o cidadão.'),
        titulo: z.string().min(3, 'Informe um título objetivo.'),
        descricao: z.string().min(1, 'Descreva a solicitação.'),
        categoria_id: z.string(),
        estado: z.string(),
        municipio: z.string(),
        bairro_id: z.string(),
        responsavel_id: z.string(),
        endereco: z.string(),
        cep: z.string(),
        numero: z.string(),
        complemento: z.string(),
        ponto_referencia: z.string(),
        latitude: z.string(),
        longitude: z.string(),
        prioridade: z.enum(['baixa', 'normal', 'alta', 'urgente']),
        origem: z.enum([
            'whatsapp',
            'telefone',
            'atendimento_presencial',
            'visita_bairro',
            'rede_social',
            'email',
            'outro',
        ]),
        prazo_data: z.string(),
        prazo_hora: z.string(),
    })
    .superRefine((values, context) => {
        if (Boolean(values.prazo_data) !== Boolean(values.prazo_hora)) {
            context.addIssue({
                code: 'custom',
                path: [values.prazo_data ? 'prazo_hora' : 'prazo_data'],
                message: 'Informe a data e a hora do prazo.',
            });
        }
    });

type Values = z.infer<typeof schema>;
const value = (item: string | null | undefined) => item ?? '';
const dateTimeParts = (item: string | null | undefined) => {
    const value = item ? new Date(item).toISOString().slice(0, 16) : '';
    const [date = '', time = ''] = value.split('T');

    return { date, time };
};

export function DemandForm({
    demand,
    options,
    officeLocation,
}: {
    demand?: Demand;
    options: DemandOptions;
    officeLocation?: OfficeLocation;
}) {
    const tenantUrl = useTenantUrl();
    const deadline = dateTimeParts(demand?.prazo);
    const {
        control,
        register,
        handleSubmit,
        setError,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            cidadao_id: demand?.cidadao_id.toString() ?? '',
            titulo: value(demand?.titulo),
            descricao: value(demand?.descricao),
            categoria_id: demand?.categoria_id?.toString() ?? '',
            estado: value(demand?.bairro?.estado ?? officeLocation?.estado),
            municipio: value(
                demand?.bairro?.municipio ?? officeLocation?.municipio,
            ),
            bairro_id: demand?.bairro_id?.toString() ?? '',
            responsavel_id: demand?.responsavel_id?.toString() ?? '',
            endereco: value(demand?.endereco),
            numero: value(demand?.numero),
            cep: '',
            complemento: value(demand?.complemento),
            ponto_referencia: value(demand?.ponto_referencia),
            latitude: value(demand?.latitude),
            longitude: value(demand?.longitude),
            prioridade: demand?.prioridade ?? 'normal',
            origem: demand?.origem ?? 'whatsapp',
            prazo_data: deadline.date,
            prazo_hora: deadline.time,
        },
    });

    const selectedCategoryId = useWatch({ control, name: 'categoria_id' });
    const selectedCategory = options.categories.find(
        (item) => item.id.toString() === selectedCategoryId,
    );

    const submit = (values: Values) => {
        const {
            prazo_data: deadlineDate,
            prazo_hora: deadlineTime,
            ...data
        } = values;
        const payload = {
            ...data,
            prazo:
                deadlineDate && deadlineTime
                    ? `${deadlineDate}T${deadlineTime}`
                    : '',
        };
        const requestOptions = {
            preserveScroll: true,
            onError: (serverErrors: Record<string, string>) =>
                Object.entries(serverErrors).forEach(([key, message]) => {
                    const field =
                        key === 'prazo' ? 'prazo_data' : (key as keyof Values);
                    setError(field, { message });
                }),
        };

        if (demand) {
            router.put(
                tenantUrl(`/demandas/${demand.id}`),
                payload,
                requestOptions,
            );
        } else {
            router.post(tenantUrl('/demandas'), payload, requestOptions);
        }
    };
    const applyCitizenAddress = (citizenId: string) => {
        const citizen = options.citizens?.find(
            (item) => item.id.toString() === citizenId,
        );

        if (!citizen) {
            return;
        }

        setValue('bairro_id', citizen.bairro_id?.toString() ?? '');
        setValue('endereco', value(citizen.endereco));
        setValue('numero', value(citizen.numero));
        setValue('complemento', value(citizen.complemento));
        setValue('ponto_referencia', value(citizen.ponto_referencia));
    };

    return (
        <form onSubmit={handleSubmit(submit)} className="space-y-6">
            <Card className="gap-0 py-0">
                <SurfaceHeader help="O protocolo e o status inicial serão definidos pelo sistema.">
                    <SurfaceTitle>Solicitação</SurfaceTitle>
                </SurfaceHeader>
                <div className="grid gap-5 p-5 md:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="cidadao_id">
                            Cidadão solicitante{' '}
                            <span aria-hidden="true">*</span>
                        </Label>
                        <Controller
                            control={control}
                            name="cidadao_id"
                            render={({ field }) => (
                                <AppSelect
                                    id="cidadao_id"
                                    value={field.value}
                                    onValueChange={(selected) => {
                                        field.onChange(selected);
                                        applyCitizenAddress(selected);
                                    }}
                                    placeholder="Selecione o cidadão"
                                    options={(options.citizens ?? []).map(
                                        (citizen) => ({
                                            value: citizen.id.toString(),
                                            label: citizen.nome,
                                        }),
                                    )}
                                    aria-invalid={Boolean(errors.cidadao_id)}
                                />
                            )}
                        />
                        <FieldError message={errors.cidadao_id?.message} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="titulo">
                            Título <span aria-hidden="true">*</span>
                        </Label>
                        <Input id="titulo" {...register('titulo')} />
                        <FieldError message={errors.titulo?.message} />
                    </div>
                    <div className="space-y-1 md:col-span-2">
                        <Label htmlFor="descricao">
                            Descrição <span aria-hidden="true">*</span>
                        </Label>
                        <Textarea
                            id="descricao"
                            rows={6}
                            {...register('descricao')}
                        />
                        <FieldError message={errors.descricao?.message} />
                    </div>
                    <SelectField
                        id="categoria_id"
                        label="Categoria"
                        error={errors.categoria_id?.message}
                        control={control}
                        name="categoria_id"
                        options={options.categories.map((item) => ({
                            value: item.id.toString(),
                            label: item.nome ?? '',
                        }))}
                        startAdornment={
                            selectedCategory ? (
                                <CategoryIconBadge
                                    name={selectedCategory.icone}
                                    color={selectedCategory.cor_semantica}
                                    className="size-6 [&_svg]:size-3.5"
                                />
                            ) : undefined
                        }
                    />
                    <SelectField
                        id="prioridade"
                        label="Prioridade"
                        required
                        control={control}
                        name="prioridade"
                        options={
                            demand
                                ? options.priorities
                                : (options.creationPriorities ??
                                  options.priorities)
                        }
                    />
                    <SelectField
                        id="origem"
                        label="Origem"
                        required
                        control={control}
                        name="origem"
                        options={options.origins}
                    />
                    <SelectField
                        id="responsavel_id"
                        label="Responsável"
                        control={control}
                        name="responsavel_id"
                        options={options.members.map((item) => ({
                            value: item.id.toString(),
                            label: item.name,
                        }))}
                    />
                    <div className="grid gap-4 sm:grid-cols-2 md:col-span-2">
                        <div className="space-y-1">
                            <Label htmlFor="prazo_data">Data do prazo</Label>
                            <Controller
                                control={control}
                                name="prazo_data"
                                render={({ field }) => (
                                    <DatePicker
                                        id="prazo_data"
                                        value={field.value}
                                        onChange={field.onChange}
                                        aria-invalid={Boolean(
                                            errors.prazo_data,
                                        )}
                                    />
                                )}
                            />
                            <FieldError message={errors.prazo_data?.message} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="prazo_hora">Hora do prazo</Label>
                            <Controller
                                control={control}
                                name="prazo_hora"
                                render={({ field }) => (
                                    <TimePicker
                                        id="prazo_hora"
                                        value={field.value}
                                        onChange={field.onChange}
                                        aria-invalid={Boolean(
                                            errors.prazo_hora,
                                        )}
                                    />
                                )}
                            />
                            <FieldError message={errors.prazo_hora?.message} />
                        </div>
                    </div>
                </div>
            </Card>

            <Card className="gap-0 py-0">
                <SurfaceHeader help="Os dados do cidadão são sugeridos e podem ser ajustados nesta demanda.">
                    <SurfaceTitle>Localização</SurfaceTitle>
                </SurfaceHeader>
                <div className="space-y-5 p-5">
                    <AddressFields
                        control={control}
                        register={register}
                        setValue={setValue}
                        errors={errors}
                        bairroName="bairro_id"
                        renderBairro={() => (
                            <SelectField
                                id="bairro_id"
                                label="Bairro"
                                error={errors.bairro_id?.message}
                                control={control}
                                name="bairro_id"
                                options={options.neighborhoods.map((item) => ({
                                    value: item.id.toString(),
                                    label: item.nome ?? '',
                                }))}
                            />
                        )}
                    />
                    <div className="grid gap-5 md:grid-cols-2">
                        <TextField
                            label="Complemento"
                            name="complemento"
                            register={register('complemento')}
                        />
                        <TextField
                            label="Ponto de referência"
                            name="ponto_referencia"
                            register={register('ponto_referencia')}
                        />
                        <TextField
                            label="Latitude"
                            name="latitude"
                            register={register('latitude')}
                        />
                        <TextField
                            label="Longitude"
                            name="longitude"
                            register={register('longitude')}
                        />
                    </div>
                </div>
            </Card>

            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => history.back()}
                >
                    Cancelar
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                    {demand ? 'Salvar alterações' : 'Registrar demanda'}
                </Button>
            </div>
        </form>
    );
}

function SelectField({
    id,
    label,
    options,
    control,
    name,
    error,
    required = false,
    startAdornment,
}: {
    id: string;
    label: string;
    options: Array<{ value: string; label: string }>;
    control: Control<Values>;
    name: FieldPath<Values>;
    error?: string;
    required?: boolean;
    startAdornment?: ReactNode;
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>
                {label} {required && <span aria-hidden="true">*</span>}
            </Label>
            <Controller
                control={control}
                name={name}
                render={({ field }) => (
                    <AppSelect
                        id={id}
                        value={field.value}
                        onValueChange={field.onChange}
                        options={options}
                        placeholder={required ? 'Selecione' : undefined}
                        emptyLabel={required ? undefined : 'Não informado'}
                        aria-invalid={Boolean(error)}
                        startAdornment={startAdornment}
                    />
                )}
            />
            <FieldError message={error} />
        </div>
    );
}

function TextField({
    label,
    name,
    register,
}: {
    label: string;
    name: string;
    register: ReturnType<typeof useForm<Values>>['register'] extends (
        name: never,
    ) => infer Result
        ? Result
        : never;
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} {...register} />
        </div>
    );
}
