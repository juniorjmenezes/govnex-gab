import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import type { Control, FieldPath } from 'react-hook-form';
import { z } from 'zod';
import { DateTimeFieldPair } from '@/components/forms/date-time-field-pair';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { Attendance, AttendanceOptions } from '@/types';

const schema = z.object({
    cidadao_id: z.string().min(1, 'Selecione o cidadão atendido.'),
    atendente_id: z.string().min(1, 'Selecione o atendente.'),
    demanda_id: z.string(),
    assunto: z.string().min(3, 'Informe o assunto do atendimento.'),
    relato: z
        .string()
        .min(10, 'Descreva o que foi relatado durante o atendimento.'),
    providencias: z.string(),
    atendido_data: z.string().min(1, 'Informe a data do atendimento.'),
    atendido_hora: z.string().min(1, 'Informe a hora do atendimento.'),
    duracao_minutos: z.string(),
    requer_retorno: z.boolean(),
    retorno_previsto_em: z.string(),
});

type Values = z.infer<typeof schema>;
type SelectFieldName = Exclude<FieldPath<Values>, 'requer_retorno'>;
const value = (item: string | null | undefined) => item ?? '';
const dateTimeParts = (item: string) => {
    const [date = '', time = ''] = item.split('T');

    return { date, time: time.slice(0, 5) };
};

export function AttendanceForm({
    attendance,
    attendedAt,
    options,
    defaults,
}: {
    attendance?: Attendance;
    attendedAt: string;
    options: AttendanceOptions;
    defaults?: {
        citizenId: number | null;
        attendantId: number;
    };
}) {
    const attendedAtParts = dateTimeParts(attendedAt);
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
            cidadao_id:
                attendance?.cidadao_id.toString() ??
                defaults?.citizenId?.toString() ??
                '',
            atendente_id:
                attendance?.atendente_id?.toString() ??
                defaults?.attendantId.toString() ??
                '',
            demanda_id: attendance?.demanda_id?.toString() ?? '',
            assunto: value(attendance?.assunto),
            relato: value(attendance?.relato),
            providencias: value(attendance?.providencias),
            atendido_data: attendedAtParts.date,
            atendido_hora: attendedAtParts.time,
            duracao_minutos: attendance?.duracao_minutos?.toString() ?? '30',
            requer_retorno: attendance?.requer_retorno ?? false,
            retorno_previsto_em: value(attendance?.retorno_previsto_em),
        },
    });
    const citizenId = useWatch({ control, name: 'cidadao_id' });
    const requiresReturn = useWatch({ control, name: 'requer_retorno' });
    const citizenDemands = options.demands.filter(
        (demand) => demand.cidadao_id?.toString() === citizenId,
    );

    const submit = (values: Values) => {
        const payload = {
            ...values,
            atendido_em: `${values.atendido_data}T${values.atendido_hora}`,
        };
        const requestOptions = {
            preserveScroll: true,
            onError: (serverErrors: Record<string, string>) =>
                Object.entries(serverErrors).forEach(([key, message]) => {
                    const field =
                        key === 'atendido_em'
                            ? 'atendido_data'
                            : (key as keyof Values);
                    setError(field, { message });
                }),
        };

        if (attendance) {
            router.put(
                `/atendimentos/${attendance.id}`,
                payload,
                requestOptions,
            );
        } else {
            router.post('/atendimentos', payload, requestOptions);
        }
    };

    return (
        <form onSubmit={handleSubmit(submit)} className="space-y-6">
            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Identificação
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Informe quem foi atendido e quem realizou o atendimento
                        no gabinete.
                    </p>
                </div>
                <div className="grid gap-5 p-5 md:grid-cols-2">
                    <SelectField
                        id="cidadao_id"
                        label="Cidadão atendido"
                        required
                        control={control}
                        name="cidadao_id"
                        error={errors.cidadao_id?.message}
                        options={options.citizens.map((citizen) => ({
                            value: citizen.id.toString(),
                            label: `${citizen.nome}${citizen.eleitor ? ' — eleitor' : ''}`,
                        }))}
                        onValueChange={() =>
                            setValue('demanda_id', '', {
                                shouldDirty: true,
                            })
                        }
                    />
                    <SelectField
                        id="atendente_id"
                        label="Atendente"
                        required
                        control={control}
                        name="atendente_id"
                        error={errors.atendente_id?.message}
                        options={options.members.map((member) => ({
                            value: member.id.toString(),
                            label: member.name,
                        }))}
                    />
                    <DateTimeFieldPair
                        required
                        className="md:col-span-2"
                        dateLabel="Data do atendimento"
                        timeLabel="Hora do atendimento"
                        dateInputProps={{
                            id: 'atendido_data',
                            ...register('atendido_data'),
                        }}
                        timeInputProps={{
                            id: 'atendido_hora',
                            ...register('atendido_hora'),
                        }}
                        dateError={errors.atendido_data?.message}
                        timeError={errors.atendido_hora?.message}
                    />
                    <div className="space-y-1">
                        <Label htmlFor="duracao_minutos">
                            Duração em minutos
                        </Label>
                        <Input
                            id="duracao_minutos"
                            type="number"
                            min={1}
                            max={1440}
                            {...register('duracao_minutos')}
                            aria-invalid={Boolean(errors.duracao_minutos)}
                        />
                        <FieldError message={errors.duracao_minutos?.message} />
                    </div>
                    {options.capabilities.demands && (
                        <SelectField
                            id="demanda_id"
                            label="Demanda relacionada"
                            control={control}
                            name="demanda_id"
                            error={errors.demanda_id?.message}
                            options={citizenDemands.map((demand) => ({
                                value: demand.id.toString(),
                                label: `${demand.protocolo} — ${demand.titulo}`,
                            }))}
                        />
                    )}
                </div>
            </Card>

            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Registro do atendimento
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Registre o motivo da visita, o relato e as providências
                        adotadas.
                    </p>
                </div>
                <div className="space-y-5 p-5">
                    <div className="space-y-1">
                        <Label htmlFor="assunto">
                            Assunto <span aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="assunto"
                            {...register('assunto')}
                            aria-invalid={Boolean(errors.assunto)}
                        />
                        <FieldError message={errors.assunto?.message} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="relato">
                            Relato <span aria-hidden="true">*</span>
                        </Label>
                        <Textarea
                            id="relato"
                            rows={7}
                            {...register('relato')}
                            aria-invalid={Boolean(errors.relato)}
                        />
                        <FieldError message={errors.relato?.message} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="providencias">
                            Providências e orientações
                        </Label>
                        <Textarea
                            id="providencias"
                            rows={5}
                            {...register('providencias')}
                            aria-invalid={Boolean(errors.providencias)}
                        />
                        <FieldError message={errors.providencias?.message} />
                    </div>
                </div>
            </Card>

            <Card className="gap-4 p-5">
                <Label className="items-start gap-3">
                    <Controller
                        control={control}
                        name="requer_retorno"
                        render={({ field }) => (
                            <Switch
                                className="mt-0.5"
                                checked={field.value}
                                onCheckedChange={(checked) => {
                                    field.onChange(checked);

                                    if (!checked) {
                                        setValue('retorno_previsto_em', '', {
                                            shouldDirty: true,
                                        });
                                    }
                                }}
                                aria-label="Necessita retorno"
                            />
                        )}
                    />
                    <span>
                        <strong className="block">Necessita retorno</strong>
                        <span className="text-muted-foreground">
                            Marque quando o gabinete precisar retornar ao
                            cidadão.
                        </span>
                    </span>
                </Label>
                {requiresReturn && (
                    <div className="max-w-sm space-y-1">
                        <Label htmlFor="retorno_previsto_em">
                            Retorno previsto <span aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="retorno_previsto_em"
                            type="date"
                            {...register('retorno_previsto_em')}
                            aria-invalid={Boolean(errors.retorno_previsto_em)}
                        />
                        <FieldError
                            message={errors.retorno_previsto_em?.message}
                        />
                    </div>
                )}
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
                    {attendance ? 'Salvar alterações' : 'Registrar atendimento'}
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
    onValueChange,
}: {
    id: string;
    label: string;
    options: Array<{ value: string; label: string }>;
    control: Control<Values>;
    name: SelectFieldName;
    error?: string;
    required?: boolean;
    onValueChange?: (value: string) => void;
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
                        onValueChange={(selected) => {
                            field.onChange(selected);
                            onValueChange?.(selected);
                        }}
                        options={options}
                        placeholder={required ? 'Selecione' : undefined}
                        emptyLabel={required ? undefined : 'Não informado'}
                        aria-invalid={Boolean(error)}
                    />
                )}
            />
            <FieldError message={error} />
        </div>
    );
}
