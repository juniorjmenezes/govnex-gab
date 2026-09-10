import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import type {
    Control,
    FieldPath,
    UseFormRegisterReturn,
} from 'react-hook-form';
import { z } from 'zod';
import { FieldError } from '@/components/forms/field-error';
import { FieldLabel } from '@/components/forms/field-label';
import { AddIcon, CloseIcon, MagnifierIcon } from '@/components/icons';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { DateTimeParts, EventOptions, OfficeEvent } from '@/types';

const schema = z
    .object({
        titulo: z.string().min(3, 'Informe o título do evento.'),
        tipo: z.enum([
            'reuniao',
            'evento_publico',
            'ato_politico',
            'assembleia',
            'outro',
        ]),
        status: z.enum([
            'planejado',
            'confirmado',
            'em_andamento',
            'concluido',
            'cancelado',
        ]),
        duracao: z.enum(['unico_dia', 'multiplos_dias']),
        inicio_data: z.string().min(1, 'Informe a data de início.'),
        inicio_hora: z.string().min(1, 'Informe a hora de início.'),
        fim_data: z.string().min(1, 'Informe a data de término.'),
        fim_hora: z.string().min(1, 'Informe a hora de término.'),
        local: z.string(),
        responsavel_id: z.string(),
        participantes_usuarios: z.array(z.number()),
        participantes_cidadaos: z.array(z.number()),
        descricao: z.string(),
        observacoes: z.string(),
    })
    .superRefine((values, context) => {
        if (
            values.duracao === 'multiplos_dias' &&
            values.inicio_data &&
            values.fim_data &&
            values.fim_data <= values.inicio_data
        ) {
            context.addIssue({
                code: 'custom',
                path: ['fim_data'],
                message:
                    'A data final deve ser posterior em eventos de vários dias.',
            });
        }

        if (
            values.inicio_hora &&
            values.fim_hora &&
            values.fim_hora <= values.inicio_hora
        ) {
            context.addIssue({
                code: 'custom',
                path: ['fim_hora'],
                message:
                    'O horário diário de término deve ser posterior ao início.',
            });
        }
    });

type Values = z.infer<typeof schema>;
type SelectName = Extract<
    FieldPath<Values>,
    'tipo' | 'status' | 'duracao' | 'responsavel_id'
>;
const value = (item: string | null | undefined) => item ?? '';

export function EventForm({
    event,
    options,
    dateTime,
    responsibleId,
}: {
    event?: OfficeEvent;
    options: EventOptions;
    dateTime: { start: DateTimeParts; end: DateTimeParts };
    responsibleId?: number;
}) {
    const tenantUrl = useTenantUrl();
    const {
        control,
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            titulo: value(event?.titulo),
            tipo: event?.tipo ?? 'reuniao',
            status: event?.status ?? 'planejado',
            duracao: event?.duracao ?? 'unico_dia',
            inicio_data: dateTime.start.date,
            inicio_hora: dateTime.start.time,
            fim_data: dateTime.end.date,
            fim_hora: dateTime.end.time,
            local: value(event?.local),
            responsavel_id:
                event?.responsavel_id?.toString() ??
                responsibleId?.toString() ??
                '',
            participantes_usuarios:
                event?.participantes_usuarios?.map(
                    (participant) => participant.id,
                ) ?? [],
            participantes_cidadaos:
                event?.participantes_cidadaos?.map(
                    (participant) => participant.id,
                ) ?? [],
            descricao: value(event?.descricao),
            observacoes: value(event?.observacoes),
        },
    });
    const duration = useWatch({ control, name: 'duracao' });

    const submit = (values: Values) => {
        const endDate =
            values.duracao === 'unico_dia'
                ? values.inicio_data
                : values.fim_data;
        const payload = {
            titulo: values.titulo,
            tipo: values.tipo,
            status: values.status,
            duracao: values.duracao,
            inicio_em: `${values.inicio_data}T${values.inicio_hora}`,
            fim_em: `${endDate}T${values.fim_hora}`,
            local: values.local,
            responsavel_id: values.responsavel_id,
            participantes_usuarios: values.participantes_usuarios,
            participantes_cidadaos: values.participantes_cidadaos,
            descricao: values.descricao,
            observacoes: values.observacoes,
        };
        const requestOptions = {
            preserveScroll: true,
            onError: (serverErrors: Record<string, string>) =>
                Object.entries(serverErrors).forEach(([key, message]) => {
                    const field =
                        key === 'inicio_em'
                            ? 'inicio_data'
                            : key === 'fim_em'
                              ? 'fim_data'
                              : key.startsWith('participantes_usuarios')
                                ? 'participantes_usuarios'
                                : key.startsWith('participantes_cidadaos')
                                  ? 'participantes_cidadaos'
                                  : (key as keyof Values);
                    setError(field, { message });
                }),
        };

        if (event) {
            router.put(
                tenantUrl(`/eventos/${event.id}`),
                payload,
                requestOptions,
            );
        } else {
            router.post(tenantUrl('/eventos'), payload, requestOptions);
        }
    };

    return (
        <form onSubmit={handleSubmit(submit)} className="space-y-6">
            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Identificação do evento
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Classifique o evento e defina sua situação atual.
                    </p>
                </div>
                <div className="grid gap-5 p-5 md:grid-cols-2">
                    <div className="space-y-1 md:col-span-2">
                        <Label htmlFor="titulo">
                            Título <span aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="titulo"
                            {...register('titulo')}
                            aria-invalid={Boolean(errors.titulo)}
                        />
                        <FieldError message={errors.titulo?.message} />
                    </div>
                    <SelectField
                        id="tipo"
                        label="Tipo"
                        required
                        control={control}
                        name="tipo"
                        options={options.types}
                        error={errors.tipo?.message}
                    />
                    <SelectField
                        id="status"
                        label="Status"
                        required
                        control={control}
                        name="status"
                        options={options.statuses}
                        error={errors.status?.message}
                    />
                    <div className="space-y-1">
                        <Label htmlFor="local">Local</Label>
                        <Input
                            id="local"
                            {...register('local')}
                            aria-invalid={Boolean(errors.local)}
                        />
                        <FieldError message={errors.local?.message} />
                    </div>
                    <SelectField
                        id="responsavel_id"
                        label="Responsável"
                        control={control}
                        name="responsavel_id"
                        options={options.members.map((member) => ({
                            value: member.id.toString(),
                            label: member.name,
                        }))}
                        error={errors.responsavel_id?.message}
                    />
                </div>
            </Card>

            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Período
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Defina um único dia ou um período com horário repetido
                        diariamente.
                    </p>
                </div>
                <div className="space-y-5 p-5">
                    <SelectField
                        id="duracao"
                        label="Duração"
                        required
                        control={control}
                        name="duracao"
                        options={options.durations}
                        error={errors.duracao?.message}
                        help={
                            duration === 'multiplos_dias'
                                ? 'O evento aparecerá na Agenda em cada dia do período, usando o horário diário informado.'
                                : undefined
                        }
                    />
                    <div
                        className={
                            duration === 'multiplos_dias'
                                ? 'grid gap-4 sm:grid-cols-2'
                                : 'grid gap-4'
                        }
                    >
                        <InputField
                            id="inicio_data"
                            label={
                                duration === 'multiplos_dias'
                                    ? 'Data de início'
                                    : 'Data do evento'
                            }
                            type="date"
                            required
                            register={register('inicio_data')}
                            error={errors.inicio_data?.message}
                        />
                        {duration === 'multiplos_dias' && (
                            <InputField
                                id="fim_data"
                                label="Data de término"
                                type="date"
                                required
                                register={register('fim_data')}
                                error={errors.fim_data?.message}
                            />
                        )}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <InputField
                            id="inicio_hora"
                            label={
                                duration === 'multiplos_dias'
                                    ? 'Horário diário de início'
                                    : 'Hora de início'
                            }
                            type="time"
                            required
                            register={register('inicio_hora')}
                            error={errors.inicio_hora?.message}
                        />
                        <InputField
                            id="fim_hora"
                            label={
                                duration === 'multiplos_dias'
                                    ? 'Horário diário de término'
                                    : 'Hora de término'
                            }
                            type="time"
                            required
                            register={register('fim_hora')}
                            error={errors.fim_hora?.message}
                        />
                    </div>
                </div>
            </Card>

            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Participantes
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Selecione integrantes do gabinete e cidadãos convidados.
                    </p>
                </div>
                <div className="space-y-5 p-5">
                    <InternalParticipantChecklist
                        label="Equipe do gabinete"
                        control={control}
                        options={options.members.map((member) => ({
                            id: member.id,
                            label: member.name,
                        }))}
                        error={errors.participantes_usuarios?.message}
                    />
                    <CitizenParticipantPicker
                        control={control}
                        citizens={options.citizens}
                        error={errors.participantes_cidadaos?.message}
                    />
                </div>
            </Card>

            <Card className="gap-5 p-5">
                <div className="space-y-1">
                    <Label htmlFor="descricao">Descrição</Label>
                    <Textarea
                        id="descricao"
                        rows={6}
                        {...register('descricao')}
                        aria-invalid={Boolean(errors.descricao)}
                    />
                    <FieldError message={errors.descricao?.message} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="observacoes">Observações internas</Label>
                    <Textarea
                        id="observacoes"
                        rows={4}
                        {...register('observacoes')}
                        aria-invalid={Boolean(errors.observacoes)}
                    />
                    <FieldError message={errors.observacoes?.message} />
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
                    {event ? 'Salvar alterações' : 'Criar evento'}
                </Button>
            </div>
        </form>
    );
}

function InputField({
    id,
    label,
    type,
    register,
    error,
    required = false,
}: {
    id: string;
    label: string;
    type: 'date' | 'time';
    register: UseFormRegisterReturn;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>
                {label} {required && <span aria-hidden="true">*</span>}
            </Label>
            <Input
                id={id}
                type={type}
                {...register}
                aria-invalid={Boolean(error)}
            />
            <FieldError message={error} />
        </div>
    );
}

function InternalParticipantChecklist({
    label,
    control,
    options,
    error,
}: {
    label: string;
    control: Control<Values>;
    options: Array<{ id: number; label: string }>;
    error?: string;
}) {
    return (
        <div className="space-y-1">
            <Label>{label}</Label>
            <Controller
                control={control}
                name="participantes_usuarios"
                render={({ field }) => (
                    <div className="max-h-64 overflow-y-auto rounded-md border p-3">
                        {options.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum registro disponível.
                            </p>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {options.map((option) => (
                                    <Label key={option.id}>
                                        <Checkbox
                                            checked={field.value.includes(
                                                option.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                field.onChange(
                                                    checked
                                                        ? [
                                                              ...field.value,
                                                              option.id,
                                                          ]
                                                        : field.value.filter(
                                                              (id) =>
                                                                  id !==
                                                                  option.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span>{option.label}</span>
                                    </Label>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            />
            <FieldError message={error} />
        </div>
    );
}

function CitizenParticipantPicker({
    control,
    citizens,
    error,
}: {
    control: Control<Values>;
    citizens: EventOptions['citizens'];
    error?: string;
}) {
    const tenantUrl = useTenantUrl();
    const [query, setQuery] = useState('');
    const [knownCitizens, setKnownCitizens] = useState(citizens);
    const [results, setResults] = useState<EventOptions['citizens']>([]);
    const [isSearching, setIsSearching] = useState(false);

    useEffect(() => {
        const normalizedQuery = query.trim();

        if (normalizedQuery.length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(
                    tenantUrl(
                        `/eventos/participantes/cidadaos?q=${encodeURIComponent(normalizedQuery)}`,
                    ),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );

                if (!response.ok) {
                    throw new Error('Não foi possível buscar cidadãos.');
                }

                setResults((await response.json()) as EventOptions['citizens']);
            } catch (requestError) {
                if (
                    requestError instanceof DOMException &&
                    requestError.name === 'AbortError'
                ) {
                    return;
                }

                setResults([]);
            } finally {
                if (!controller.signal.aborted) {
                    setIsSearching(false);
                }
            }
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, tenantUrl]);

    return (
        <div className="space-y-1">
            <Label htmlFor="citizen-participant-search">
                Cidadãos convidados
            </Label>
            <Controller
                control={control}
                name="participantes_cidadaos"
                render={({ field }) => {
                    const selected = knownCitizens.filter((citizen) =>
                        field.value.includes(citizen.id),
                    );
                    const availableResults = results.filter(
                        (citizen) => !field.value.includes(citizen.id),
                    );

                    return (
                        <div className="space-y-3">
                            {selected.length > 0 && (
                                <div
                                    className="flex flex-wrap gap-2"
                                    aria-label="Cidadãos selecionados"
                                >
                                    {selected.map((citizen) => (
                                        <span
                                            key={citizen.id}
                                            className="inline-flex items-center gap-1 rounded-md border bg-muted/50 py-1 pr-1 pl-2 text-sm"
                                        >
                                            {citizen.nome}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-xs"
                                                className="size-5"
                                                onClick={() =>
                                                    field.onChange(
                                                        field.value.filter(
                                                            (id) =>
                                                                id !==
                                                                citizen.id,
                                                        ),
                                                    )
                                                }
                                                aria-label={`Remover ${citizen.nome}`}
                                            >
                                                <CloseIcon />
                                            </Button>
                                        </span>
                                    ))}
                                </div>
                            )}

                            <div className="relative">
                                <MagnifierIcon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id="citizen-participant-search"
                                    type="search"
                                    value={query}
                                    onChange={(event) => {
                                        const nextQuery = event.target.value;
                                        setQuery(nextQuery);

                                        if (nextQuery.trim().length < 2) {
                                            setResults([]);
                                            setIsSearching(false);
                                        } else {
                                            setIsSearching(true);
                                        }
                                    }}
                                    className="pl-9"
                                    placeholder="Buscar cidadão pelo nome"
                                    autoComplete="off"
                                    aria-invalid={Boolean(error)}
                                />
                            </div>

                            {query.trim().length < 2 ? (
                                <p className="text-xs text-muted-foreground">
                                    Digite ao menos 2 caracteres para pesquisar.
                                </p>
                            ) : (
                                <div
                                    className="max-h-64 overflow-y-auto rounded-md border"
                                    role="listbox"
                                    aria-label="Resultados da busca de cidadãos"
                                    aria-busy={isSearching}
                                >
                                    {isSearching ? (
                                        <p className="p-3 text-sm text-muted-foreground">
                                            Buscando cidadãos...
                                        </p>
                                    ) : availableResults.length === 0 ? (
                                        <p className="p-3 text-sm text-muted-foreground">
                                            Nenhum cidadão encontrado.
                                        </p>
                                    ) : (
                                        availableResults.map((citizen) => (
                                            <button
                                                key={citizen.id}
                                                type="button"
                                                role="option"
                                                aria-selected="false"
                                                className="flex w-full items-center justify-between gap-3 border-b px-3 py-2 text-left text-sm transition-colors last:border-b-0 hover:bg-accent"
                                                onClick={() => {
                                                    setKnownCitizens(
                                                        (current) =>
                                                            current.some(
                                                                (item) =>
                                                                    item.id ===
                                                                    citizen.id,
                                                            )
                                                                ? current
                                                                : [
                                                                      ...current,
                                                                      citizen,
                                                                  ],
                                                    );
                                                    field.onChange([
                                                        ...field.value,
                                                        citizen.id,
                                                    ]);
                                                    setQuery('');
                                                    setResults([]);
                                                    setIsSearching(false);
                                                }}
                                            >
                                                <span className="truncate">
                                                    {citizen.nome}
                                                </span>
                                                <AddIcon className="size-4 shrink-0 text-muted-foreground" />
                                            </button>
                                        ))
                                    )}
                                </div>
                            )}

                            {selected.length === 0 && (
                                <p className="text-xs text-muted-foreground">
                                    Nenhum cidadão selecionado.
                                </p>
                            )}
                        </div>
                    );
                }}
            />
            <FieldError message={error} />
        </div>
    );
}

function SelectField({
    id,
    label,
    options,
    control,
    name,
    error,
    help,
    required = false,
}: {
    id: string;
    label: string;
    options: Array<{ value: string; label: string }>;
    control: Control<Values>;
    name: SelectName;
    error?: string;
    help?: string;
    required?: boolean;
}) {
    return (
        <div className="space-y-1">
            <FieldLabel htmlFor={id} help={help} helpTitle={label}>
                {label} {required && <span aria-hidden="true">*</span>}
            </FieldLabel>
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
                    />
                )}
            />
            <FieldError message={error} />
        </div>
    );
}
