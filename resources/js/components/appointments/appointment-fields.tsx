import { format, parseISO } from 'date-fns';
import type { ReactNode } from 'react';
import type {
    AppointmentFormState,
    AppointmentFormValues,
} from '@/components/appointments/use-appointment-form';
import { DatePicker } from '@/components/forms/date-picker';
import { DateTimeFieldPair } from '@/components/forms/date-time-field-pair';
import { FieldError } from '@/components/forms/field-error';
import { PeoplePicker } from '@/components/forms/people-picker';
import { ClockCircleIcon } from '@/components/icons';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import type {
    AppointmentOptions,
    AppointmentStatus,
    AppointmentCapabilities,
} from '@/types';

/**
 * Campos do compromisso, iguais na página /agenda/novo e no modal de edição
 * da agenda. O estado vem do useAppointmentForm.
 */
export function AppointmentFields({
    state,
    options,
    today,
    capabilities,
    whatsappRealEnabled,
    layout = 'dialog',
}: {
    state: AppointmentFormState;
    options: AppointmentOptions;
    today: string;
    capabilities: AppointmentCapabilities;
    whatsappRealEnabled: boolean;
    /** Em página, cada grupo vira um card; no modal, ficam soltos. */
    layout?: 'page' | 'dialog';
}) {
    const {
        form,
        editing,
        isPastEditing,
        toggleInternalReminder,
        toggleFakeWhatsAppReminder,
        toggleRealWhatsAppReminder,
        internalReminder,
        fakeWhatsAppReminder,
        realWhatsAppReminder,
        realRecipients,
    } = state;

    return (
        <div className="grid gap-5">
            {isPastEditing && editing ? (
                <>
                    <Alert variant="warning">
                        <ClockCircleIcon />
                        <AlertTitle>Compromisso anterior</AlertTitle>
                        <AlertDescription>
                            Os dados originais estão preservados. Somente a
                            situação pode ser alterada.
                        </AlertDescription>
                    </Alert>
                    <div className="rounded-md bg-muted/50 p-4">
                        <p className="font-medium">{editing.title}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {format(
                                parseISO(editing.starts_at),
                                "dd/MM/yyyy 'às' HH:mm",
                            )}
                            {editing.location && ` · ${editing.location}`}
                        </p>
                    </div>
                    <Field label="Situação" error={form.errors.status}>
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
                    <Section layout={layout} title="Compromisso">
                        <Field
                            label="Título"
                            required
                            error={form.errors.titulo}
                        >
                            <Input
                                aria-required="true"
                                value={form.data.titulo}
                                onChange={(event) =>
                                    form.setData('titulo', event.target.value)
                                }
                                maxLength={180}
                            />
                        </Field>
                        <Field label="Descrição" error={form.errors.descricao}>
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
                        {/* Os quatro campos de período dividem a mesma linha:
                            cada par entra como `contents` para que data e hora
                            virem colunas da grade externa. */}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <DateTimeFieldPair
                                required
                                className="contents"
                                dateLabel="Data de início"
                                timeLabel="Hora de início"
                                dateProps={{
                                    id: 'appointment_start_date',
                                    min: today,
                                    value: form.data.inicio_data,
                                    onChange: (date) => {
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
                                dateError={
                                    form.errors.inicio_data ??
                                    form.errors.inicio_em
                                }
                                timeError={form.errors.inicio_hora}
                            />
                            <DateTimeFieldPair
                                required
                                className="contents"
                                dateLabel="Data de término"
                                timeLabel="Hora de término"
                                dateProps={{
                                    id: 'appointment_end_date',
                                    min: today,
                                    value: form.data.fim_data,
                                    onChange: (date) => {
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
                                dateError={
                                    form.errors.fim_data ?? form.errors.fim_em
                                }
                                timeError={form.errors.fim_hora}
                            />
                        </div>
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
                                required
                                error={form.errors.tipo}
                            >
                                <Input
                                    aria-required="true"
                                    value={form.data.tipo}
                                    onChange={(event) =>
                                        form.setData('tipo', event.target.value)
                                    }
                                    placeholder="Reunião, visita, atendimento..."
                                />
                            </Field>
                            <Field label="Local" error={form.errors.local}>
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
                    </Section>

                    <Section layout={layout} title="Pessoas e vínculos">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Responsável">
                                <AppSelect
                                    value={form.data.responsavel_id?.toString()}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'responsavel_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    emptyLabel="Não atribuído"
                                    options={options.members.map((member) => ({
                                        value: member.id.toString(),
                                        label: member.name,
                                    }))}
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

                        <PeoplePicker
                            label="Participantes internos"
                            options={options.members.map((member) => ({
                                id: member.id,
                                label: member.name,
                            }))}
                            value={form.data.participantes}
                            onChange={(participantes) =>
                                form.setData('participantes', participantes)
                            }
                            error={form.errors.participantes}
                            searchPlaceholder="Buscar integrante pelo nome"
                            emptyLabel="Nenhum integrante disponível."
                            noResultsLabel="Nenhum integrante encontrado."
                            noSelectionLabel="Nenhum integrante selecionado."
                        />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Cidadão relacionado">
                                <AppSelect
                                    value={form.data.cidadao_id?.toString()}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'cidadao_id',
                                            value ? Number(value) : null,
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
                                                value ? Number(value) : null,
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
                    </Section>

                    <Section layout={layout} title="Recorrência e lembretes">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Recorrência">
                                <AppSelect
                                    value={form.data.recorrencia}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'recorrencia',
                                            value as AppointmentFormValues['recorrencia'],
                                        )
                                    }
                                    options={options.recurrences}
                                />
                            </Field>
                            {form.data.recorrencia !== 'nenhuma' && (
                                <Field label="Repetir até">
                                    <DatePicker
                                        min={form.data.inicio_data}
                                        value={form.data.recorrencia_ate}
                                        onChange={(value) =>
                                            form.setData(
                                                'recorrencia_ate',
                                                value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                        </div>

                        <div className="overflow-hidden rounded-md border">
                            <SurfaceHeader>
                                <SurfaceTitle>Lembretes</SurfaceTitle>
                            </SurfaceHeader>
                            <div className="grid gap-4 p-5">
                                <div className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center">
                                    <Checkbox
                                        checked={Boolean(internalReminder)}
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
                                        checked={Boolean(fakeWhatsAppReminder)}
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
                                            consentimento. Não realiza envio
                                            externo.
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
                                        checked={Boolean(realWhatsAppReminder)}
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
                                <FieldError message={form.errors.lembretes} />
                            </div>
                        </div>
                    </Section>

                    <Section layout={layout} title="Observações">
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
                                    {editing.reminders.map((reminder) => (
                                        <div
                                            key={reminder.id}
                                            className="flex flex-wrap items-center gap-2 text-xs"
                                        >
                                            <Badge variant="outline">
                                                {reminder.channel_label}
                                            </Badge>
                                            <Badge
                                                variant={
                                                    reminder.status === 'falhou'
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {reminder.status_label}
                                            </Badge>
                                            <span className="text-muted-foreground">
                                                {reminder.minutes_before} min
                                                antes
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Section>
                </>
            )}
        </div>
    );
}

/**
 * Grupo de campos: card com cabeçalho na página (padrão do formulário de
 * evento) e bloco simples dentro do modal de edição.
 */
function Section({
    layout,
    title,
    children,
}: {
    layout: 'page' | 'dialog';
    title: string;
    children: ReactNode;
}) {
    if (layout === 'dialog') {
        return <div className="grid gap-5">{children}</div>;
    }

    return (
        <Card className="gap-0 py-0">
            <SurfaceHeader>
                <SurfaceTitle>{title}</SurfaceTitle>
            </SurfaceHeader>
            <div className="grid gap-5 p-5">{children}</div>
        </Card>
    );
}

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label className="grid items-start gap-1">
                <span>
                    {label} {required && <span aria-hidden="true">*</span>}
                </span>
                {children}
            </Label>
            <FieldError message={error} />
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
