import { Head, router, useForm } from '@inertiajs/react';
import {
    addDays,
    addMonths,
    addWeeks,
    eachDayOfInterval,
    endOfMonth,
    endOfWeek,
    format,
    isSameDay,
    isSameMonth,
    parseISO,
    startOfMonth,
    startOfWeek,
    subDays,
    subMonths,
    subWeeks,
} from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useEffect, useMemo, useState } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogFooter,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
import { DateTimeFieldPair } from '@/components/forms/date-time-field-pair';
import {
    AddIcon,
    AltArrowLeftIcon,
    AltArrowRightIcon,
    CalendarDateIcon,
    CalendarIcon,
    ChatRoundDotsIcon,
    ClockCircleIcon,
    MapPointIcon,
    RestartIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type {
    Appointment,
    AppointmentPageProps,
    AppointmentStatus,
} from '@/types';

type ReminderInput = {
    canal: 'interno' | 'whatsapp_simulado' | 'whatsapp';
    antecedencia_minutos: number;
    destinatarios: Array<number | string>;
    ativo: boolean;
};

type AppointmentForm = {
    titulo: string;
    descricao: string;
    inicio_em: string;
    fim_em: string;
    inicio_data: string;
    inicio_hora: string;
    fim_data: string;
    fim_hora: string;
    dia_inteiro: boolean;
    local: string;
    responsavel_id: number | null;
    participantes: number[];
    cidadao_id: number | null;
    demanda_id: number | null;
    tipo: string;
    status: AppointmentStatus;
    observacoes: string;
    recorrencia: 'nenhuma' | 'diaria' | 'semanal' | 'mensal';
    recorrencia_ate: string;
    lembretes: ReminderInput[];
};

const views = [
    { value: 'mes', label: 'Mês' },
    { value: 'semana', label: 'Semana' },
    { value: 'dia', label: 'Dia' },
    { value: 'lista', label: 'Lista' },
] as const;

const statusClass: Record<AppointmentStatus, string> = {
    agendado:
        'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/50 dark:text-blue-200',
    confirmado:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200',
    concluido:
        'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
    cancelado:
        'border-red-200 bg-red-50 text-red-700 line-through dark:border-red-900 dark:bg-red-950/40 dark:text-red-200',
};

function toLocalInput(value: string) {
    const date = parseISO(value);

    return format(date, "yyyy-MM-dd'T'HH:mm");
}

function defaultDates(date: string) {
    const start = parseISO(`${date}T09:00:00`);

    return {
        start: format(start, "yyyy-MM-dd'T'HH:mm"),
        end: format(
            addDays(start, 0).setHours(start.getHours() + 1),
            "yyyy-MM-dd'T'HH:mm",
        ),
    };
}

/**
 * Vindo do painel de "próxima ação" de uma demanda, via link com esses
 * parâmetros: permite abrir o formulário de criação já preenchido, poupando
 * o usuário de retranscrever descrição/data/responsável manualmente.
 */
function readNextActionPrefill(): {
    titulo: string;
    data: string | null;
    responsavelId: number | null;
    demandaId: number | null;
} | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const params = new URLSearchParams(window.location.search);

    if (params.get('nova_reuniao') !== '1') {
        return null;
    }

    const demandaId = params.get('criar_demanda_id');
    const responsavelId = params.get('criar_responsavel_id');

    return {
        titulo: params.get('criar_titulo') ?? '',
        data: params.get('criar_data'),
        responsavelId: responsavelId ? Number(responsavelId) : null,
        demandaId: demandaId ? Number(demandaId) : null,
    };
}

function appointmentToForm(appointment: Appointment): AppointmentForm {
    const start = toLocalInput(appointment.series_starts_at);
    const end = toLocalInput(appointment.series_ends_at);

    return {
        titulo: appointment.title,
        descricao: appointment.description ?? '',
        inicio_em: start,
        fim_em: end,
        inicio_data: start.slice(0, 10),
        inicio_hora: start.slice(11, 16),
        fim_data: end.slice(0, 10),
        fim_hora: end.slice(11, 16),
        dia_inteiro: appointment.all_day,
        local: appointment.location ?? '',
        responsavel_id: appointment.responsible?.id ?? null,
        participantes: appointment.participants.map(
            (participant) => participant.id,
        ),
        cidadao_id: appointment.citizen?.id ?? null,
        demanda_id: appointment.demand?.id ?? null,
        tipo: appointment.type,
        status: appointment.status,
        observacoes: appointment.notes ?? '',
        recorrencia: appointment.recurrence,
        recorrencia_ate: appointment.recurrence_until ?? '',
        lembretes: appointment.reminders
            .filter((reminder) => reminder.status === 'pendente')
            .map((reminder) => ({
                canal: reminder.channel,
                antecedencia_minutos: reminder.minutes_before,
                destinatarios: reminder.recipients,
                ativo: reminder.active,
            })),
    };
}

export default function AppointmentsIndex({
    appointments,
    canDelete,
    filters,
    timezone,
    today,
    whatsappSimulated,
    whatsappRealEnabled,
    capabilities,
    options,
}: AppointmentPageProps) {
    const tenantUrl = useTenantUrl();
    const [nextActionPrefill] = useState(readNextActionPrefill);
    const [editing, setEditing] = useState<Appointment | null>(null);
    const [dialogOpen, setDialogOpen] = useState(nextActionPrefill !== null);
    const [statusSaving, setStatusSaving] = useState(false);
    const isPastEditing = editing?.is_past ?? false;
    const date = parseISO(filters.date);
    const initialDates = defaultDates(nextActionPrefill?.data ?? filters.date);
    const form = useForm<AppointmentForm>({
        titulo: nextActionPrefill?.titulo ?? '',
        descricao: '',
        inicio_em: initialDates.start,
        fim_em: initialDates.end,
        inicio_data: initialDates.start.slice(0, 10),
        inicio_hora: initialDates.start.slice(11, 16),
        fim_data: initialDates.end.slice(0, 10),
        fim_hora: initialDates.end.slice(11, 16),
        dia_inteiro: false,
        local: '',
        responsavel_id: nextActionPrefill?.responsavelId ?? null,
        participantes: [],
        cidadao_id: null,
        demanda_id: capabilities.demands
            ? (nextActionPrefill?.demandaId ?? null)
            : null,
        tipo: 'reuniao',
        status: 'agendado',
        observacoes: '',
        recorrencia: 'nenhuma',
        recorrencia_ate: '',
        lembretes: [],
    });

    const calendarDays = useMemo(() => {
        const start = startOfWeek(startOfMonth(date), { locale: ptBR });
        const end = endOfWeek(endOfMonth(date), { locale: ptBR });

        return eachDayOfInterval({ start, end });
    }, [date]);

    const openCreate = (selectedDate = today) => {
        if (selectedDate < today) {
            return;
        }

        const dates = defaultDates(selectedDate);
        setEditing(null);
        form.setData({
            titulo: '',
            descricao: '',
            inicio_em: dates.start,
            fim_em: dates.end,
            inicio_data: dates.start.slice(0, 10),
            inicio_hora: dates.start.slice(11, 16),
            fim_data: dates.end.slice(0, 10),
            fim_hora: dates.end.slice(11, 16),
            dia_inteiro: false,
            local: '',
            responsavel_id: null,
            participantes: [],
            cidadao_id: null,
            demanda_id: null,
            tipo: 'reuniao',
            status: 'agendado',
            observacoes: '',
            recorrencia: 'nenhuma',
            recorrencia_ate: '',
            lembretes: [],
        });
        form.clearErrors();
        setDialogOpen(true);
    };

    // O dialog de criação já abre pré-preenchido (estado inicial acima) —
    // aqui só tiramos os parâmetros da URL para não reabri-lo de novo ao
    // atualizar a página ou voltar para ela.
    useEffect(() => {
        if (nextActionPrefill) {
            window.history.replaceState(null, '', window.location.pathname);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const openEdit = (appointment: Appointment) => {
        setEditing(appointment);
        const values = appointmentToForm(appointment);

        if (!capabilities.demands) {
            values.demanda_id = null;
        }

        if (!capabilities.whatsapp) {
            values.lembretes = values.lembretes.filter(
                (reminder) =>
                    reminder.canal !== 'whatsapp' &&
                    reminder.canal !== 'whatsapp_simulado',
            );
        }

        form.setData(values);
        form.clearErrors();
        setDialogOpen(true);
    };

    const openAgendaItem = (appointment: Appointment) => {
        if (appointment.source === 'event') {
            router.visit(tenantUrl(`/eventos/${appointment.id}`));

            return;
        }

        openEdit(appointment);
    };

    const navigate = (target: Date, view = filters.view) => {
        router.get(
            tenantUrl('/agenda'),
            {
                ...filters,
                view,
                date: format(target, 'yyyy-MM-dd'),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const previous = () => {
        const target =
            filters.view === 'mes'
                ? subMonths(date, 1)
                : filters.view === 'semana'
                  ? subWeeks(date, 1)
                  : subDays(date, 1);
        navigate(target);
    };

    const next = () => {
        const target =
            filters.view === 'mes'
                ? addMonths(date, 1)
                : filters.view === 'semana'
                  ? addWeeks(date, 1)
                  : addDays(date, 1);
        navigate(target);
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setDialogOpen(false),
        };

        if (editing?.is_past) {
            setStatusSaving(true);
            router.patch(
                tenantUrl(`/agenda/${editing.id}/status`),
                { status: form.data.status },
                {
                    ...options,
                    onFinish: () => setStatusSaving(false),
                },
            );
        } else if (editing) {
            form.transform((data) => ({
                ...data,
                lembretes: data.lembretes.map((reminder) =>
                    reminder.canal === 'whatsapp'
                        ? { ...reminder, destinatarios: realRecipients }
                        : reminder,
                ),
            }));
            form.put(tenantUrl(`/agenda/${editing.id}`), options);
        } else {
            form.transform((data) => ({
                ...data,
                lembretes: data.lembretes.map((reminder) =>
                    reminder.canal === 'whatsapp'
                        ? { ...reminder, destinatarios: realRecipients }
                        : reminder,
                ),
            }));
            form.post(tenantUrl('/agenda'), options);
        }
    };

    const toggleInternalReminder = (enabled: boolean) => {
        const others = form.data.lembretes.filter(
            (reminder) => reminder.canal !== 'interno',
        );
        form.setData(
            'lembretes',
            enabled
                ? [
                      ...others,
                      {
                          canal: 'interno',
                          antecedencia_minutos: 30,
                          destinatarios:
                              form.data.participantes.length > 0
                                  ? form.data.participantes
                                  : form.data.responsavel_id
                                    ? [form.data.responsavel_id]
                                    : [],
                          ativo: true,
                      },
                  ]
                : others,
        );
    };

    const toggleFakeWhatsAppReminder = (enabled: boolean) => {
        const others = form.data.lembretes.filter(
            (reminder) => reminder.canal !== 'whatsapp_simulado',
        );
        form.setData(
            'lembretes',
            enabled
                ? [
                      ...others,
                      {
                          canal: 'whatsapp_simulado',
                          antecedencia_minutos: 60,
                          destinatarios: ['cidadao'],
                          ativo: true,
                      },
                  ]
                : others,
        );
    };

    const selectedCitizen = options.citizens.find(
        (citizen) => citizen.id === form.data.cidadao_id,
    );
    const realRecipients = [
        ...new Set([
            ...(selectedCitizen?.whatsapp_ready ? ['cidadao'] : []),
            ...[
                ...form.data.participantes,
                ...(form.data.responsavel_id ? [form.data.responsavel_id] : []),
            ].filter((id) =>
                options.members.some(
                    (member) => member.id === id && member.whatsapp_ready,
                ),
            ),
        ]),
    ];

    const toggleRealWhatsAppReminder = (enabled: boolean) => {
        const others = form.data.lembretes.filter(
            (reminder) => reminder.canal !== 'whatsapp',
        );
        form.setData(
            'lembretes',
            enabled
                ? [
                      ...others,
                      {
                          canal: 'whatsapp',
                          antecedencia_minutos: 60,
                          destinatarios: realRecipients,
                          ativo: true,
                      },
                  ]
                : others,
        );
    };

    const internalReminder = form.data.lembretes.find(
        (reminder) => reminder.canal === 'interno',
    );
    const fakeWhatsAppReminder = form.data.lembretes.find(
        (reminder) => reminder.canal === 'whatsapp_simulado',
    );
    const realWhatsAppReminder = form.data.lembretes.find(
        (reminder) => reminder.canal === 'whatsapp',
    );

    return (
        <>
            <Head title="Agenda" />
            <PageContainer>
                <PageHeader
                    title="Agenda do gabinete"
                    description={`Compromissos, eventos, participantes e lembretes em ${timezone}.`}
                    actions={
                        <Button onClick={() => openCreate()}>
                            <AddIcon aria-hidden="true" />
                            Novo compromisso
                        </Button>
                    }
                />

                {whatsappSimulated && (
                    <Alert>
                        <ChatRoundDotsIcon className="size-4" />
                        <AlertTitle>WhatsApp em modo simulado</AlertTitle>
                        <AlertDescription>
                            O fluxo é processado pela fila e auditado, mas
                            nenhuma mensagem externa é enviada.
                        </AlertDescription>
                    </Alert>
                )}

                {whatsappRealEnabled && (
                    <Alert>
                        <ChatRoundDotsIcon className="size-4" />
                        <AlertTitle>Gateway WhatsApp disponível</AlertTitle>
                        <AlertDescription>
                            O canal real aparece somente para contatos com
                            consentimento, finalidade e template ativos.
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardContent className="flex flex-col gap-3 p-4 xl:flex-row xl:items-center xl:justify-between">
                        <div className="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon-sm"
                                onClick={previous}
                            >
                                <AltArrowLeftIcon />
                                <span className="sr-only">
                                    Período anterior
                                </span>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => navigate(new Date())}
                            >
                                Hoje
                            </Button>
                            <Button
                                variant="outline"
                                size="icon-sm"
                                onClick={next}
                            >
                                <AltArrowRightIcon />
                                <span className="sr-only">Próximo período</span>
                            </Button>
                            <h2 className="ml-2 text-base font-semibold capitalize">
                                {format(date, 'MMMM yyyy', { locale: ptBR })}
                            </h2>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <AppSelect
                                className="w-44"
                                value={filters.responsavel_id?.toString()}
                                onValueChange={(value) =>
                                    router.get(
                                        tenantUrl('/agenda'),
                                        {
                                            ...filters,
                                            responsavel_id: value || undefined,
                                        },
                                        { preserveState: true },
                                    )
                                }
                                emptyLabel="Toda a equipe"
                                options={options.members.map((member) => ({
                                    value: member.id.toString(),
                                    label: member.name,
                                }))}
                            />
                            <div className="flex items-center rounded-md border p-0.5">
                                {views.map((item) => (
                                    <Button
                                        key={item.value}
                                        variant={
                                            filters.view === item.value
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        size="sm"
                                        className="h-7 px-2.5"
                                        onClick={() =>
                                            navigate(date, item.value)
                                        }
                                    >
                                        {item.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {filters.view === 'mes' && (
                    <MonthView
                        days={calendarDays}
                        date={date}
                        appointments={appointments}
                        today={today}
                        onCreate={openCreate}
                        onSelect={openAgendaItem}
                    />
                )}
                {filters.view === 'semana' && (
                    <PeriodGrid
                        days={eachDayOfInterval({
                            start: startOfWeek(date, { locale: ptBR }),
                            end: endOfWeek(date, { locale: ptBR }),
                        })}
                        appointments={appointments}
                        today={today}
                        onCreate={openCreate}
                        onSelect={openAgendaItem}
                    />
                )}
                {filters.view === 'dia' && (
                    <PeriodGrid
                        days={[date]}
                        appointments={appointments}
                        today={today}
                        onCreate={openCreate}
                        onSelect={openAgendaItem}
                    />
                )}
                {filters.view === 'lista' && (
                    <ListView
                        appointments={appointments}
                        onSelect={openAgendaItem}
                    />
                )}
            </PageContainer>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <ScrollableDialogContent className="sm:max-w-3xl">
                    <ScrollableDialogHeader>
                        <DialogTitle>
                            {isPastEditing
                                ? 'Atualizar situação'
                                : editing
                                  ? 'Editar compromisso'
                                  : 'Novo compromisso'}
                        </DialogTitle>
                        <DialogDescription>
                            {isPastEditing
                                ? 'Compromissos de dias anteriores permitem somente alterar a situação.'
                                : `Horários são salvos e exibidos em ${timezone}.`}
                        </DialogDescription>
                    </ScrollableDialogHeader>

                    <ScrollableDialogBody className="grid gap-5">
                        {isPastEditing && editing ? (
                            <>
                                <Alert>
                                    <ClockCircleIcon className="size-4" />
                                    <AlertTitle>
                                        Compromisso anterior
                                    </AlertTitle>
                                    <AlertDescription>
                                        Os dados originais estão preservados.
                                        Somente a situação pode ser alterada.
                                    </AlertDescription>
                                </Alert>
                                <div className="rounded-md bg-muted/50 p-4">
                                    <p className="font-medium">
                                        {editing.title}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {format(
                                            parseISO(editing.starts_at),
                                            "dd/MM/yyyy 'às' HH:mm",
                                        )}
                                        {editing.location &&
                                            ` · ${editing.location}`}
                                    </p>
                                </div>
                                <Field
                                    label="Situação"
                                    error={form.errors.status}
                                >
                                    <AppSelect
                                        value={form.data.status}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'status',
                                                value as AppointmentStatus,
                                            )
                                        }
                                        options={options.statuses}
                                    />
                                </Field>
                            </>
                        ) : (
                            <>
                                <Field
                                    label="Título"
                                    error={form.errors.titulo}
                                >
                                    <Input
                                        value={form.data.titulo}
                                        onChange={(event) =>
                                            form.setData(
                                                'titulo',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={180}
                                    />
                                </Field>
                                <Field
                                    label="Descrição"
                                    error={form.errors.descricao}
                                >
                                    <Textarea
                                        value={form.data.descricao}
                                        onChange={(event) =>
                                            form.setData(
                                                'descricao',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <DateTimeFieldPair
                                    required
                                    dateLabel="Data de início"
                                    timeLabel="Hora de início"
                                    dateInputProps={{
                                        id: 'appointment_start_date',
                                        min: today,
                                        value: form.data.inicio_data,
                                        onChange: (event) => {
                                            const date = event.target.value;
                                            form.setData({
                                                ...form.data,
                                                inicio_data: date,
                                                inicio_em: `${date}T${form.data.inicio_hora}`,
                                            });
                                        },
                                    }}
                                    timeInputProps={{
                                        id: 'appointment_start_time',
                                        value: form.data.inicio_hora,
                                        onChange: (event) => {
                                            const time = event.target.value;
                                            form.setData({
                                                ...form.data,
                                                inicio_hora: time,
                                                inicio_em: `${form.data.inicio_data}T${time}`,
                                            });
                                        },
                                    }}
                                    dateError={form.errors.inicio_em}
                                />
                                <DateTimeFieldPair
                                    required
                                    dateLabel="Data de término"
                                    timeLabel="Hora de término"
                                    dateInputProps={{
                                        id: 'appointment_end_date',
                                        min: today,
                                        value: form.data.fim_data,
                                        onChange: (event) => {
                                            const date = event.target.value;
                                            form.setData({
                                                ...form.data,
                                                fim_data: date,
                                                fim_em: `${date}T${form.data.fim_hora}`,
                                            });
                                        },
                                    }}
                                    timeInputProps={{
                                        id: 'appointment_end_time',
                                        value: form.data.fim_hora,
                                        onChange: (event) => {
                                            const time = event.target.value;
                                            form.setData({
                                                ...form.data,
                                                fim_hora: time,
                                                fim_em: `${form.data.fim_data}T${time}`,
                                            });
                                        },
                                    }}
                                    dateError={form.errors.fim_em}
                                />
                                <Label>
                                    <Checkbox
                                        checked={form.data.dia_inteiro}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'dia_inteiro',
                                                checked === true,
                                            )
                                        }
                                    />
                                    Compromisso de dia inteiro
                                </Label>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Tipo"
                                        error={form.errors.tipo}
                                    >
                                        <Input
                                            value={form.data.tipo}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tipo',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Reunião, visita, atendimento..."
                                        />
                                    </Field>
                                    <Field
                                        label="Local"
                                        error={form.errors.local}
                                    >
                                        <Input
                                            value={form.data.local}
                                            onChange={(event) =>
                                                form.setData(
                                                    'local',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Responsável">
                                        <AppSelect
                                            value={form.data.responsavel_id?.toString()}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'responsavel_id',
                                                    value
                                                        ? Number(value)
                                                        : null,
                                                )
                                            }
                                            emptyLabel="Não atribuído"
                                            options={options.members.map(
                                                (member) => ({
                                                    value: member.id.toString(),
                                                    label: member.name,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    <Field label="Situação">
                                        <AppSelect
                                            value={form.data.status}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'status',
                                                    value as AppointmentStatus,
                                                )
                                            }
                                            options={options.statuses}
                                        />
                                    </Field>
                                </div>

                                <Field label="Participantes internos">
                                    <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
                                        {options.members.map((member) => (
                                            <Label key={member.id}>
                                                <Checkbox
                                                    checked={form.data.participantes.includes(
                                                        member.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) => {
                                                        const participants =
                                                            checked
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .participantes,
                                                                      member.id,
                                                                  ]
                                                                : form.data.participantes.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          member.id,
                                                                  );
                                                        form.setData(
                                                            'participantes',
                                                            participants,
                                                        );
                                                    }}
                                                />
                                                {member.name}
                                            </Label>
                                        ))}
                                    </div>
                                </Field>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Cidadão relacionado">
                                        <AppSelect
                                            value={form.data.cidadao_id?.toString()}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'cidadao_id',
                                                    value
                                                        ? Number(value)
                                                        : null,
                                                )
                                            }
                                            emptyLabel="Nenhum cidadão"
                                            options={options.citizens.map(
                                                (citizen) => ({
                                                    value: citizen.id.toString(),
                                                    label: citizen.nome,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    {capabilities.demands && (
                                        <Field label="Demanda relacionada">
                                            <AppSelect
                                                value={form.data.demanda_id?.toString()}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'demanda_id',
                                                        value
                                                            ? Number(value)
                                                            : null,
                                                    )
                                                }
                                                emptyLabel="Nenhuma demanda"
                                                options={options.demands.map(
                                                    (demand) => ({
                                                        value: demand.id.toString(),
                                                        label: `${demand.protocolo} · ${demand.titulo}`,
                                                    }),
                                                )}
                                            />
                                        </Field>
                                    )}
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Recorrência">
                                        <AppSelect
                                            value={form.data.recorrencia}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'recorrencia',
                                                    value as AppointmentForm['recorrencia'],
                                                )
                                            }
                                            options={options.recurrences}
                                        />
                                    </Field>
                                    {form.data.recorrencia !== 'nenhuma' && (
                                        <Field label="Repetir até">
                                            <Input
                                                type="date"
                                                min={form.data.inicio_data}
                                                value={
                                                    form.data.recorrencia_ate
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'recorrencia_ate',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    )}
                                </div>

                                <Card>
                                    <CardHeader className="border-b">
                                        <CardTitle>Lembretes</CardTitle>
                                    </CardHeader>
                                    <CardContent className="grid gap-4">
                                        <div className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center">
                                            <Checkbox
                                                checked={Boolean(
                                                    internalReminder,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleInternalReminder(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium">
                                                    Notificação interna
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Enviada aos participantes
                                                    selecionados.
                                                </p>
                                            </div>
                                            {internalReminder && (
                                                <ReminderMinutes
                                                    value={
                                                        internalReminder.antecedencia_minutos
                                                    }
                                                    onChange={(minutes) =>
                                                        form.setData(
                                                            'lembretes',
                                                            form.data.lembretes.map(
                                                                (reminder) =>
                                                                    reminder.canal ===
                                                                    'interno'
                                                                        ? {
                                                                              ...reminder,
                                                                              antecedencia_minutos:
                                                                                  minutes,
                                                                              destinatarios:
                                                                                  form
                                                                                      .data
                                                                                      .participantes
                                                                                      .length >
                                                                                  0
                                                                                      ? form
                                                                                            .data
                                                                                            .participantes
                                                                                      : form
                                                                                              .data
                                                                                              .responsavel_id
                                                                                        ? [
                                                                                              form
                                                                                                  .data
                                                                                                  .responsavel_id,
                                                                                          ]
                                                                                        : [],
                                                                          }
                                                                        : reminder,
                                                            ),
                                                        )
                                                    }
                                                />
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center">
                                            <Checkbox
                                                checked={Boolean(
                                                    fakeWhatsAppReminder,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleFakeWhatsAppReminder(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium">
                                                    WhatsApp simulado
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Exige cidadão com WhatsApp e
                                                    consentimento. Não realiza
                                                    envio externo.
                                                </p>
                                            </div>
                                            {fakeWhatsAppReminder && (
                                                <ReminderMinutes
                                                    value={
                                                        fakeWhatsAppReminder.antecedencia_minutos
                                                    }
                                                    onChange={(minutes) =>
                                                        form.setData(
                                                            'lembretes',
                                                            form.data.lembretes.map(
                                                                (reminder) =>
                                                                    reminder.canal ===
                                                                    'whatsapp_simulado'
                                                                        ? {
                                                                              ...reminder,
                                                                              antecedencia_minutos:
                                                                                  minutes,
                                                                          }
                                                                        : reminder,
                                                            ),
                                                        )
                                                    }
                                                />
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center">
                                            <Checkbox
                                                checked={Boolean(
                                                    realWhatsAppReminder,
                                                )}
                                                disabled={
                                                    !whatsappRealEnabled ||
                                                    realRecipients.length === 0
                                                }
                                                onCheckedChange={(checked) =>
                                                    toggleRealWhatsAppReminder(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium">
                                                    WhatsApp
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {realRecipients.length > 0
                                                        ? `${realRecipients.length} destinatário(s) elegível(is).`
                                                        : 'Nenhum participante possui o canal pronto.'}
                                                </p>
                                            </div>
                                            {realWhatsAppReminder && (
                                                <ReminderMinutes
                                                    value={
                                                        realWhatsAppReminder.antecedencia_minutos
                                                    }
                                                    onChange={(minutes) =>
                                                        form.setData(
                                                            'lembretes',
                                                            form.data.lembretes.map(
                                                                (reminder) =>
                                                                    reminder.canal ===
                                                                    'whatsapp'
                                                                        ? {
                                                                              ...reminder,
                                                                              antecedencia_minutos:
                                                                                  minutes,
                                                                          }
                                                                        : reminder,
                                                            ),
                                                        )
                                                    }
                                                />
                                            )}
                                        </div>
                                        {form.errors.lembretes && (
                                            <p className="text-sm text-destructive">
                                                {form.errors.lembretes}
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>

                                <Field
                                    label="Observações"
                                    error={form.errors.observacoes}
                                >
                                    <Textarea
                                        value={form.data.observacoes}
                                        onChange={(event) =>
                                            form.setData(
                                                'observacoes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>

                                {editing && editing.reminders.length > 0 && (
                                    <div className="rounded-md border p-3">
                                        <p className="text-sm font-medium">
                                            Histórico de lembretes
                                        </p>
                                        <div className="mt-2 grid gap-2">
                                            {editing.reminders.map(
                                                (reminder) => (
                                                    <div
                                                        key={reminder.id}
                                                        className="flex flex-wrap items-center gap-2 text-xs"
                                                    >
                                                        <Badge variant="outline">
                                                            {
                                                                reminder.channel_label
                                                            }
                                                        </Badge>
                                                        <Badge
                                                            variant={
                                                                reminder.status ===
                                                                'falhou'
                                                                    ? 'destructive'
                                                                    : 'secondary'
                                                            }
                                                        >
                                                            {
                                                                reminder.status_label
                                                            }
                                                        </Badge>
                                                        <span className="text-muted-foreground">
                                                            {
                                                                reminder.minutes_before
                                                            }{' '}
                                                            min antes
                                                        </span>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </ScrollableDialogBody>

                    <ScrollableDialogFooter className="gap-2 sm:justify-between">
                        <div className="flex gap-2">
                            {editing && canDelete && (
                                <DeleteRecordButton
                                    url={tenantUrl(`/agenda/${editing.id}`)}
                                    label={`Excluir ${editing.title}`}
                                    title="Excluir compromisso?"
                                    description="O compromisso e seus lembretes deixarão de aparecer na agenda. O registro permanecerá preservado no banco de dados."
                                    onSuccess={() => setDialogOpen(false)}
                                />
                            )}
                            {editing &&
                                !isPastEditing &&
                                editing.status !== 'cancelado' && (
                                    <Button
                                        type="button"
                                        variant="destructive-solid"
                                        onClick={() => {
                                            router.patch(
                                                tenantUrl(
                                                    `/agenda/${editing.id}/cancelar`,
                                                ),
                                                {},
                                                {
                                                    onSuccess: () =>
                                                        setDialogOpen(false),
                                                },
                                            );
                                        }}
                                    >
                                        Cancelar compromisso
                                    </Button>
                                )}
                        </div>
                        <div className="flex gap-2">
                            <Button
                                variant="ghost"
                                onClick={() => setDialogOpen(false)}
                            >
                                Fechar
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={form.processing || statusSaving}
                            >
                                {(form.processing || statusSaving) && (
                                    <RestartIcon className="mr-2 size-4 animate-spin" />
                                )}
                                {isPastEditing ? 'Salvar situação' : 'Salvar'}
                            </Button>
                        </div>
                    </ScrollableDialogFooter>
                </ScrollableDialogContent>
            </Dialog>
        </>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label className="grid items-start gap-1">
                <span>{label}</span>
                {children}
            </Label>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

function ReminderMinutes({
    value,
    onChange,
}: {
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <div className="flex items-center gap-2">
            <Input
                type="number"
                min={0}
                max={525600}
                value={value}
                onChange={(event) => onChange(Number(event.target.value))}
                className="w-28"
                aria-label="Antecedência personalizada em minutos"
            />
            <span className="text-xs text-muted-foreground">min antes</span>
        </div>
    );
}

function AppointmentButton({
    appointment,
    onSelect,
    compact = false,
}: {
    appointment: Appointment;
    onSelect: (appointment: Appointment) => void;
    compact?: boolean;
}) {
    const timeLabel = appointment.all_day
        ? 'Dia inteiro'
        : format(parseISO(appointment.starts_at), 'HH:mm');
    const accessibleLabel = `${appointment.source === 'event' ? 'Evento · ' : ''}${timeLabel} · ${appointment.title}`;

    return (
        <button
            type="button"
            onClick={() => onSelect(appointment)}
            title={accessibleLabel}
            aria-label={accessibleLabel}
            className={cn(
                'block w-full max-w-full min-w-0 overflow-hidden rounded-md border px-2 py-1.5 text-left transition-colors hover:brightness-95 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                statusClass[appointment.status],
                appointment.source === 'event' &&
                    'border-violet-300 ring-1 ring-violet-300/50 dark:border-violet-700 dark:ring-violet-700/50',
            )}
        >
            <p className="flex w-full min-w-0 items-center gap-1 text-xs font-semibold">
                {appointment.source === 'event' && (
                    <CalendarDateIcon
                        aria-hidden="true"
                        className="size-3 shrink-0"
                    />
                )}
                <span className="truncate">
                    {!appointment.all_day &&
                        `${format(parseISO(appointment.starts_at), 'HH:mm')} · `}
                    {appointment.title}
                </span>
            </p>
            {!compact && appointment.responsible && (
                <p className="mt-0.5 truncate text-[11px] opacity-80">
                    {appointment.responsible.name}
                </p>
            )}
        </button>
    );
}

function MonthView({
    days,
    date,
    appointments,
    today,
    onCreate,
    onSelect,
}: {
    days: Date[];
    date: Date;
    appointments: Appointment[];
    today: string;
    onCreate: (date: string) => void;
    onSelect: (appointment: Appointment) => void;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <div className="hidden grid-cols-7 border-b bg-muted/40 md:grid">
                {['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].map(
                    (day) => (
                        <div
                            key={day}
                            className="px-3 py-2 text-center text-xs font-medium text-muted-foreground"
                        >
                            {day}
                        </div>
                    ),
                )}
            </div>
            <div className="grid md:grid-cols-7">
                {days.map((day) => {
                    const items = appointments.filter((appointment) =>
                        isSameDay(parseISO(appointment.starts_at), day),
                    );
                    const isPastDay = format(day, 'yyyy-MM-dd') < today;

                    return (
                        <div
                            key={day.toISOString()}
                            className={cn(
                                'min-h-28 min-w-0 overflow-hidden border-b p-2 md:border-r',
                                !isSameMonth(day, date) && 'bg-muted/25',
                            )}
                        >
                            <button
                                type="button"
                                onClick={() =>
                                    onCreate(format(day, 'yyyy-MM-dd'))
                                }
                                disabled={isPastDay}
                                title={
                                    isPastDay
                                        ? 'Não é permitido criar compromissos em dias anteriores.'
                                        : undefined
                                }
                                className={cn(
                                    'mb-1 flex size-7 items-center justify-center rounded-full text-xs font-medium hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent',
                                    isSameDay(day, new Date()) &&
                                        'bg-primary text-primary-foreground hover:bg-primary',
                                )}
                                aria-label={`Novo compromisso em ${format(day, 'dd/MM/yyyy')}`}
                            >
                                {format(day, 'd')}
                            </button>
                            <div className="grid min-w-0 gap-1 overflow-hidden">
                                {items.slice(0, 3).map((appointment) => (
                                    <AppointmentButton
                                        key={appointment.occurrence_key}
                                        appointment={appointment}
                                        onSelect={onSelect}
                                        compact
                                    />
                                ))}
                                {items.length > 3 && (
                                    <p className="px-1 text-[11px] text-muted-foreground">
                                        + {items.length - 3} item(ns)
                                    </p>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

function PeriodGrid({
    days,
    appointments,
    today,
    onCreate,
    onSelect,
}: {
    days: Date[];
    appointments: Appointment[];
    today: string;
    onCreate: (date: string) => void;
    onSelect: (appointment: Appointment) => void;
}) {
    return (
        <div className={cn('grid gap-3', days.length > 1 && 'lg:grid-cols-7')}>
            {days.map((day) => {
                const items = appointments.filter((appointment) =>
                    isSameDay(parseISO(appointment.starts_at), day),
                );
                const isPastDay = format(day, 'yyyy-MM-dd') < today;

                return (
                    <Card key={day.toISOString()} className="min-w-0">
                        <CardHeader className="flex-row items-center justify-between border-b">
                            <div>
                                <CardTitle>
                                    {format(day, 'EEEE', { locale: ptBR })}
                                </CardTitle>
                                <p className="text-xs text-muted-foreground">
                                    {format(day, 'dd/MM')}
                                </p>
                            </div>
                            <Button
                                size="icon-sm"
                                variant="ghost"
                                onClick={() =>
                                    onCreate(format(day, 'yyyy-MM-dd'))
                                }
                                disabled={isPastDay}
                                title={
                                    isPastDay
                                        ? 'Não é permitido criar compromissos em dias anteriores.'
                                        : 'Novo compromisso'
                                }
                            >
                                <AddIcon />
                            </Button>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {items.length === 0 ? (
                                <p className="py-5 text-center text-xs text-muted-foreground">
                                    Sem compromissos ou eventos
                                </p>
                            ) : (
                                items.map((appointment) => (
                                    <AppointmentButton
                                        key={appointment.occurrence_key}
                                        appointment={appointment}
                                        onSelect={onSelect}
                                    />
                                ))
                            )}
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}

function ListView({
    appointments,
    onSelect,
}: {
    appointments: Appointment[];
    onSelect: (appointment: Appointment) => void;
}) {
    if (appointments.length === 0) {
        return (
            <Card>
                <CardContent className="flex flex-col items-center py-16 text-center">
                    <CalendarIcon className="size-9 text-muted-foreground" />
                    <p className="mt-3 font-medium">
                        Nenhum compromisso ou evento no período
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Crie um compromisso ou evento, ou ajuste os filtros.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="grid gap-3">
            {appointments.map((appointment) => (
                <Card
                    key={appointment.occurrence_key}
                    className="cursor-pointer transition-colors hover:bg-accent/30"
                    onClick={() => onSelect(appointment)}
                >
                    <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                        <div className="w-20 shrink-0">
                            <p className="text-sm font-semibold">
                                {format(
                                    parseISO(appointment.starts_at),
                                    'dd MMM',
                                    {
                                        locale: ptBR,
                                    },
                                )}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {appointment.all_day
                                    ? 'Dia inteiro'
                                    : format(
                                          parseISO(appointment.starts_at),
                                          'HH:mm',
                                      )}
                            </p>
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium">
                                    {appointment.title}
                                </p>
                                <Badge variant="outline">
                                    {appointment.status_label}
                                </Badge>
                                {appointment.source === 'event' && (
                                    <Badge
                                        variant="secondary"
                                        className="gap-1"
                                    >
                                        <CalendarDateIcon className="size-3" />
                                        Evento · {appointment.type}
                                    </Badge>
                                )}
                            </div>
                            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                {appointment.location && (
                                    <span className="flex items-center gap-1">
                                        <MapPointIcon className="size-3" />
                                        {appointment.location}
                                    </span>
                                )}
                                {appointment.responsible && (
                                    <span className="flex items-center gap-1">
                                        <UsersGroupRoundedIcon className="size-3" />
                                        {appointment.responsible.name}
                                    </span>
                                )}
                                <span className="flex items-center gap-1">
                                    <ClockCircleIcon className="size-3" />
                                    {format(
                                        parseISO(appointment.ends_at),
                                        'dd/MM HH:mm',
                                    )}
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

AppointmentsIndex.layout = {
    breadcrumbs: [{ title: 'Agenda', href: '/agenda' }],
};
