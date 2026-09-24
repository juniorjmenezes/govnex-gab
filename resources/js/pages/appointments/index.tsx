import { Head, router } from '@inertiajs/react';
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
import { useMemo, useState } from 'react';
import { AppointmentFields } from '@/components/appointments/appointment-fields';
import { useAppointmentForm } from '@/components/appointments/use-appointment-form';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogFooter,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
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
import { Dialog, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useCanWrite } from '@/hooks/use-can-write';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';
import type {
    Appointment,
    AppointmentPageProps,
    AppointmentStatus,
} from '@/types';

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
    const canWrite = useCanWrite();
    const [editing, setEditing] = useState<Appointment | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const appointmentForm = useAppointmentForm({
        appointment: editing,
        options,
        capabilities,
        today,
        onSuccess: () => setDialogOpen(false),
    });
    const { form, submit, statusSaving, isPastEditing } = appointmentForm;
    const date = parseISO(filters.date);

    const calendarDays = useMemo(() => {
        const start = startOfWeek(startOfMonth(date), { locale: ptBR });
        const end = endOfWeek(endOfMonth(date), { locale: ptBR });

        return eachDayOfInterval({ start, end });
    }, [date]);

    // Criar abre a página própria: são campos demais para um modal.
    const openCreate = (selectedDate = today) => {
        if (selectedDate < today) {
            return;
        }

        router.get(tenantUrl('/agenda/novo'), { data: selectedDate });
    };

    const openEdit = (appointment: Appointment) => {
        setEditing(appointment);
        appointmentForm.loadAppointment(appointment);
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

    return (
        <>
            <Head title="Agenda" />
            <PageContainer>
                <PageHeader
                    title="Agenda do gabinete"
                    description={`Compromissos, eventos, participantes e lembretes em ${timezone}.`}
                    actions={
                        canWrite ? (
                            <Button onClick={() => openCreate()}>
                                <AddIcon aria-hidden="true" />
                                Novo compromisso
                            </Button>
                        ) : undefined
                    }
                />

                {whatsappSimulated && (
                    <Alert variant="warning">
                        <ChatRoundDotsIcon />
                        <AlertTitle>WhatsApp em modo simulado</AlertTitle>
                        <AlertDescription>
                            O fluxo é processado pela fila e auditado, mas
                            nenhuma mensagem externa é enviada.
                        </AlertDescription>
                    </Alert>
                )}

                {whatsappRealEnabled && (
                    <Alert variant="info">
                        <ChatRoundDotsIcon />
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
                            {!canWrite
                                ? 'Detalhes do compromisso'
                                : isPastEditing
                                  ? 'Atualizar situação'
                                  : editing
                                    ? 'Editar compromisso'
                                    : 'Novo compromisso'}
                        </DialogTitle>
                        <DialogDescription>
                            {!canWrite
                                ? 'Seu perfil permite apenas consultar este compromisso.'
                                : isPastEditing
                                  ? 'Compromissos de dias anteriores permitem somente alterar a situação.'
                                  : `Horários são salvos e exibidos em ${timezone}.`}
                        </DialogDescription>
                    </ScrollableDialogHeader>

                    <ScrollableDialogBody>
                        <fieldset
                            disabled={!canWrite}
                            className="min-w-0 border-0 p-0"
                        >
                            <AppointmentFields
                                state={appointmentForm}
                                options={options}
                                today={today}
                                capabilities={capabilities}
                                whatsappRealEnabled={whatsappRealEnabled}
                            />
                        </fieldset>
                    </ScrollableDialogBody>

                    <ScrollableDialogFooter className="gap-2 sm:justify-between">
                        <div className="flex gap-2">
                            {canWrite && editing && canDelete && (
                                <DeleteRecordButton
                                    url={tenantUrl(`/agenda/${editing.id}`)}
                                    label={`Excluir ${editing.title}`}
                                    title="Excluir compromisso?"
                                    subject={editing.title}
                                    subjectDetail={format(
                                        parseISO(editing.starts_at),
                                        "dd/MM/yyyy 'às' HH:mm",
                                    )}
                                    description="O compromisso e seus lembretes deixarão de aparecer na agenda. O registro permanecerá preservado no banco de dados."
                                    onSuccess={() => setDialogOpen(false)}
                                />
                            )}
                            {canWrite &&
                                editing &&
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
                            {canWrite && (
                                <Button
                                    onClick={submit}
                                    disabled={form.processing || statusSaving}
                                >
                                    {(form.processing || statusSaving) && (
                                        <RestartIcon className="mr-2 size-4 animate-spin" />
                                    )}
                                    {isPastEditing
                                        ? 'Salvar situação'
                                        : 'Salvar'}
                                </Button>
                            )}
                        </div>
                    </ScrollableDialogFooter>
                </ScrollableDialogContent>
            </Dialog>
        </>
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
    const canWrite = useCanWrite();

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
                                disabled={isPastDay || !canWrite}
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
                                aria-label={
                                    canWrite
                                        ? `Novo compromisso em ${format(day, 'dd/MM/yyyy')}`
                                        : format(day, 'dd/MM/yyyy')
                                }
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
    const canWrite = useCanWrite();

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
                            {canWrite && (
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
                            )}
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
