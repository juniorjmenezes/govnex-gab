import { router, useForm } from '@inertiajs/react';
import { addDays, format, parseISO } from 'date-fns';
import { useState } from 'react';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { checkRequiredFields } from '@/lib/required-fields';
import type {
    Appointment,
    AppointmentCapabilities,
    AppointmentOptions,
    AppointmentStatus,
} from '@/types';

type ReminderInput = {
    canal: 'interno' | 'whatsapp_simulado' | 'whatsapp';
    antecedencia_minutos: number;
    destinatarios: Array<number | string>;
    ativo: boolean;
};

type AppointmentFormValues = {
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

/**
 * Estado do formulário de compromisso (novo e edição) num lugar só: a página
 * /agenda/novo e o modal de edição da agenda compartilham este hook e o
 * <AppointmentFields>, para os campos não divergirem entre os dois.
 */
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

function appointmentToForm(appointment: Appointment): AppointmentFormValues {
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

export function useAppointmentForm({
    appointment,
    options,
    capabilities,
    today,
    initialDate,
    onSuccess,
}: {
    appointment?: Appointment | null;
    options: AppointmentOptions;
    capabilities: AppointmentCapabilities;
    today: string;
    initialDate?: string;
    onSuccess?: () => void;
}) {
    const tenantUrl = useTenantUrl();
    const editing = appointment ?? null;
    const isPastEditing = editing?.is_past ?? false;
    const [nextActionPrefill] = useState(readNextActionPrefill);
    const [statusSaving, setStatusSaving] = useState(false);
    const initialDates = defaultDates(
        nextActionPrefill?.data ?? initialDate ?? today,
    );
    const form = useForm<AppointmentFormValues>(
        editing
            ? appointmentToForm(editing)
            : {
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
              },
    );

    const submit = () => {
        if (
            !editing?.is_past &&
            !checkRequiredFields(form.data, form, {
                titulo: 'Informe o título do compromisso.',
                inicio_data: 'Informe a data de início.',
                inicio_hora: 'Informe a hora de início.',
                fim_data: 'Informe a data de término.',
                fim_hora: 'Informe a hora de término.',
                tipo: 'Informe o tipo do compromisso.',
            })
        ) {
            return;
        }

        const visitOptions = {
            preserveScroll: true,
            onSuccess,
        };

        if (editing?.is_past) {
            setStatusSaving(true);
            router.patch(
                tenantUrl(`/agenda/${editing.id}/status`),
                { status: form.data.status },
                {
                    ...visitOptions,
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
            form.put(tenantUrl(`/agenda/${editing.id}`), visitOptions);
        } else {
            form.transform((data) => ({
                ...data,
                lembretes: data.lembretes.map((reminder) =>
                    reminder.canal === 'whatsapp'
                        ? { ...reminder, destinatarios: realRecipients }
                        : reminder,
                ),
            }));
            form.post(tenantUrl('/agenda'), visitOptions);
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

    /** Carrega um compromisso existente no formulário, respeitando os
     * módulos ativos do gabinete. */
    const loadAppointment = (appointment: Appointment) => {
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
    };

    return {
        form,
        submit,
        loadAppointment,
        editing,
        isPastEditing,
        statusSaving,
        toggleInternalReminder,
        toggleFakeWhatsAppReminder,
        toggleRealWhatsAppReminder,
        internalReminder,
        fakeWhatsAppReminder,
        realWhatsAppReminder,
        realRecipients,
    };
}

export type { AppointmentFormValues };

export type AppointmentFormState = ReturnType<typeof useAppointmentForm>;
