import { Head, router, useForm } from '@inertiajs/react';
import { addHours, format, parseISO } from 'date-fns';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { DateTimeFieldPair } from '@/components/forms/date-time-field-pair';
import { FieldError } from '@/components/forms/field-error';
import { PeoplePicker } from '@/components/forms/people-picker';
import { ChatRoundDotsIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogMedia,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { checkRequiredFields } from '@/lib/required-fields';
import type {
    AppointmentStatus,
    InstitutionalAppointmentFormProps,
    InstitutionalAppointmentInput,
} from '@/types';

type FormValues = {
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
    tipo: string;
    status: AppointmentStatus;
    /** '' = sem comissão (público escolhido abaixo). */
    comissao_id: string;
    restrito: boolean;
    gabinetes: number[];
};

function initialValues(
    appointment: InstitutionalAppointmentInput | null,
    date: string,
): FormValues {
    const start = appointment?.inicio_em ?? `${date}T09:00`;
    const end =
        appointment?.fim_em ??
        format(addHours(parseISO(start), 2), "yyyy-MM-dd'T'HH:mm");

    return {
        titulo: appointment?.titulo ?? '',
        descricao: appointment?.descricao ?? '',
        inicio_em: start,
        fim_em: end,
        inicio_data: start.slice(0, 10),
        inicio_hora: start.slice(11, 16),
        fim_data: end.slice(0, 10),
        fim_hora: end.slice(11, 16),
        dia_inteiro: appointment?.dia_inteiro ?? false,
        local: appointment?.local ?? '',
        tipo: appointment?.tipo ?? '',
        status: appointment?.status ?? 'agendado',
        comissao_id: appointment?.comissao_id
            ? String(appointment.comissao_id)
            : '',
        // Ao tirar a comissão, a lista manual já vem com quem foi convocado.
        restrito: (appointment?.gabinetes.length ?? 0) > 0,
        gabinetes: appointment?.gabinetes ?? [],
    };
}

/**
 * Compromisso institucional da Câmara, publicado pela Secretaria legislativa
 * na agenda dos gabinetes de vereador. Página própria, como o compromisso do
 * gabinete: além do período, há a escolha de público.
 */
export default function InstitutionalAppointmentForm({
    appointment,
    today,
    timezone,
    defaults,
    options,
    broadcast,
}: InstitutionalAppointmentFormProps) {
    const tenantUrl = useTenantUrl();
    const form = useForm<FormValues>(initialValues(appointment, defaults.date));
    const title = appointment
        ? 'Editar compromisso institucional'
        : 'Novo compromisso institucional';
    // Compromisso que já começou mantém a data de início (só a situação e os
    // detalhes mudam); o servidor recusa movê-lo para o passado.
    const minDate = appointment?.is_past ? undefined : today;
    const committee =
        options.committees.find(
            (item) => String(item.id) === form.data.comissao_id,
        ) ?? null;
    // Mesma comissão da gravação: vale o retrato já gravado, não a
    // composição atual (o servidor só tira retrato novo ao trocar de comissão).
    const keepsSnapshot =
        committee !== null && appointment?.comissao_id === committee.id;
    const convened =
        committee === null
            ? []
            : keepsSnapshot
              ? (appointment?.gabinetes ?? [])
              : committee.offices;
    const officeLabel = (id: number): string => {
        const office = options.offices.find((item) => item.id === id);

        if (!office) {
            return `Gabinete ${id}`;
        }

        return office.leader
            ? `${office.name} · ${office.leader}`
            : office.name;
    };

    const submit = () => {
        if (
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

        if (
            committee === null &&
            form.data.restrito &&
            form.data.gabinetes.length === 0
        ) {
            form.setError(
                'gabinetes',
                'Selecione ao menos um gabinete ou publique para todos.',
            );

            return;
        }

        // Com comissão, o público sai dos membros dela no servidor.
        form.transform((data) => ({
            ...data,
            comissao_id:
                data.comissao_id === '' ? null : Number(data.comissao_id),
            gabinetes:
                data.comissao_id === '' && data.restrito ? data.gabinetes : [],
        }));

        if (appointment) {
            form.put(tenantUrl(`/agenda/institucional/${appointment.id}`), {
                preserveScroll: true,
            });
        } else {
            form.post(tenantUrl('/agenda/institucional'), {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title={title} />
            <PageContainer>
                <PageHeader
                    title={title}
                    description={`Publicado pela Secretaria legislativa na agenda dos gabinetes de vereador. Horários em ${timezone}.`}
                />
                <form
                    noValidate
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <Section title="Compromisso">
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
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <DateTimeFieldPair
                                required
                                className="contents"
                                dateLabel="Data de início"
                                timeLabel="Hora de início"
                                dateProps={{
                                    id: 'institutional_start_date',
                                    min: minDate,
                                    value: form.data.inicio_data,
                                    onChange: (date) =>
                                        form.setData({
                                            ...form.data,
                                            inicio_data: date,
                                            inicio_em: `${date}T${form.data.inicio_hora}`,
                                        }),
                                }}
                                timeInputProps={{
                                    id: 'institutional_start_time',
                                    value: form.data.inicio_hora,
                                    onChange: (event) =>
                                        form.setData({
                                            ...form.data,
                                            inicio_hora: event.target.value,
                                            inicio_em: `${form.data.inicio_data}T${event.target.value}`,
                                        }),
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
                                    id: 'institutional_end_date',
                                    min: minDate,
                                    value: form.data.fim_data,
                                    onChange: (date) =>
                                        form.setData({
                                            ...form.data,
                                            fim_data: date,
                                            fim_em: `${date}T${form.data.fim_hora}`,
                                        }),
                                }}
                                timeInputProps={{
                                    id: 'institutional_end_time',
                                    value: form.data.fim_hora,
                                    onChange: (event) =>
                                        form.setData({
                                            ...form.data,
                                            fim_hora: event.target.value,
                                            fim_em: `${form.data.fim_data}T${event.target.value}`,
                                        }),
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
                        <div className="grid gap-4 sm:grid-cols-3">
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
                                    maxLength={80}
                                    placeholder="Sessão, reunião de comissão, audiência..."
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
                                    maxLength={220}
                                />
                            </Field>
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
                        </div>
                    </Section>

                    <Section
                        title="Público"
                        description={
                            committee
                                ? `${convened.length} ${convened.length === 1 ? 'gabinete' : 'gabinetes'} da comissão`
                                : form.data.restrito
                                  ? `${form.data.gabinetes.length} de ${options.offices.length} gabinetes`
                                  : `Todos os ${options.offices.length} gabinetes de vereador`
                        }
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Reunião de comissão"
                                error={form.errors.comissao_id}
                            >
                                <AppSelect
                                    value={form.data.comissao_id}
                                    onValueChange={(value) => {
                                        form.setData('comissao_id', value);
                                        form.clearErrors('comissao_id');
                                    }}
                                    emptyLabel="Nenhuma comissão"
                                    placeholder="Nenhuma comissão"
                                    options={options.committees.map((item) => ({
                                        value: String(item.id),
                                        label: item.is_active
                                            ? item.name
                                            : `${item.name} · extinta`,
                                    }))}
                                />
                            </Field>
                        </div>
                        {committee ? (
                            <div className="grid gap-2 text-sm">
                                <p className="text-muted-foreground">
                                    {keepsSnapshot
                                        ? 'Convocados os membros da comissão no momento em que o compromisso foi vinculado a ela. Mudanças posteriores na composição não alteram este compromisso.'
                                        : 'Serão convocados os membros ativos da comissão neste momento. Mudanças posteriores na composição não alteram este compromisso.'}
                                </p>
                                {convened.length > 0 ? (
                                    <ul className="grid gap-1">
                                        {convened.map((id) => (
                                            <li key={id}>{officeLabel(id)}</li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-warning">
                                        Esta comissão não tem membros ativos.
                                        Inclua membros antes de marcar a
                                        reunião.
                                    </p>
                                )}
                            </div>
                        ) : (
                            <Label>
                                <Checkbox
                                    checked={form.data.restrito}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'restrito',
                                            checked === true,
                                        )
                                    }
                                />
                                Somente para gabinetes selecionados
                            </Label>
                        )}
                        {committee === null && form.data.restrito && (
                            <PeoplePicker
                                label="Gabinetes convocados"
                                options={options.offices.map((office) => ({
                                    id: office.id,
                                    label: office.leader
                                        ? `${office.name} · ${office.leader}`
                                        : office.name,
                                }))}
                                value={form.data.gabinetes}
                                onChange={(gabinetes) =>
                                    form.setData('gabinetes', gabinetes)
                                }
                                error={
                                    form.errors.gabinetes ??
                                    Object.entries(form.errors).find(([key]) =>
                                        key.startsWith('gabinetes.'),
                                    )?.[1]
                                }
                                searchPlaceholder="Buscar gabinete ou vereador"
                                emptyLabel="Nenhum gabinete de vereador ativo nesta Câmara."
                                noResultsLabel="Nenhum gabinete encontrado."
                                noSelectionLabel="Nenhum gabinete selecionado."
                            />
                        )}
                    </Section>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3">
                            {appointment && (
                                <DeleteRecordButton
                                    url={tenantUrl(
                                        `/agenda/institucional/${appointment.id}`,
                                    )}
                                    label={`Excluir ${appointment.titulo}`}
                                    title="Excluir compromisso institucional?"
                                    subject={appointment.titulo}
                                    subjectDetail={format(
                                        parseISO(appointment.inicio_em),
                                        "dd/MM/yyyy 'às' HH:mm",
                                    )}
                                    description="O compromisso deixará de aparecer na agenda dos gabinetes de vereador. O registro permanecerá preservado no banco de dados."
                                />
                            )}
                            {appointment && broadcast?.available && (
                                <BroadcastButton
                                    url={tenantUrl(
                                        `/agenda/institucional/${appointment.id}/convocar`,
                                    )}
                                    subject={appointment.titulo}
                                    subjectDetail={format(
                                        parseISO(appointment.inicio_em),
                                        "dd/MM/yyyy 'às' HH:mm",
                                    )}
                                    offices={broadcast.offices}
                                    unsaved={form.isDirty}
                                />
                            )}
                        </div>
                        <div className="flex justify-end gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.get(tenantUrl('/agenda'))}
                            >
                                Cancelar
                            </Button>
                            <Button disabled={form.processing}>
                                {appointment
                                    ? 'Salvar alterações'
                                    : 'Publicar compromisso'}
                            </Button>
                        </div>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

/**
 * Convocação por WhatsApp da equipe dos gabinetes do público. Ação de
 * confirmação com resultado de uma linha (toast), por isso um AlertDialog e
 * não uma página. A mensagem sai com os dados gravados: com alterações não
 * salvas, a confirmação fica bloqueada até salvar.
 */
function BroadcastButton({
    url,
    subject,
    subjectDetail,
    offices,
    unsaved,
}: {
    url: string;
    subject: string;
    subjectDetail: string;
    offices: number;
    unsaved: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const audience =
        offices === 1
            ? 'do gabinete convocado'
            : `dos ${offices} gabinetes convocados`;

    const send = () => {
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => setOpen(false),
            },
        );
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!submitting || next) {
                    setOpen(next);
                }
            }}
        >
            <AlertDialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={offices === 0}
                >
                    <ChatRoundDotsIcon aria-hidden="true" />
                    Convocar por WhatsApp
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent className="gap-0 overflow-hidden p-0">
                <AlertDialogHeader className="p-6">
                    <AlertDialogMedia className="bg-primary/10 text-primary">
                        <ChatRoundDotsIcon aria-hidden="true" />
                    </AlertDialogMedia>
                    <AlertDialogTitle>Convocar por WhatsApp?</AlertDialogTitle>
                    <AlertDialogDescription>
                        A equipe {audience} recebe o título, a data, o horário e
                        o local deste compromisso. Só chega a quem já deu
                        consentimento e está em gabinete com o WhatsApp e a
                        convocação institucional habilitados. Quem já recebeu
                        não recebe de novo, a menos que o horário mude.
                    </AlertDialogDescription>
                    <div className="col-start-2 mt-3 min-w-0 rounded-md border bg-muted/40 px-3 py-2 text-left">
                        <p className="text-sm font-medium break-words text-foreground">
                            {subject}
                        </p>
                        <p className="text-xs break-words text-muted-foreground">
                            {subjectDetail}
                        </p>
                    </div>
                    {unsaved && (
                        <p className="col-start-2 mt-3 text-sm text-warning">
                            Há alterações não salvas. Salve o compromisso antes
                            de convocar: a mensagem usa os dados gravados.
                        </p>
                    )}
                </AlertDialogHeader>
                <AlertDialogFooter className="mx-0 mb-0">
                    <AlertDialogCancel disabled={submitting}>
                        Cancelar
                    </AlertDialogCancel>
                    <AlertDialogAction
                        disabled={unsaved || submitting}
                        aria-busy={submitting}
                        onClick={(event) => {
                            event.preventDefault();
                            send();
                        }}
                    >
                        {submitting ? 'Enviando...' : 'Convocar'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <Card className="gap-0 py-0">
            <SurfaceHeader>
                <SurfaceTitle>{title}</SurfaceTitle>
                {description && (
                    <SurfaceDescription>{description}</SurfaceDescription>
                )}
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
    children: ReactNode;
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

InstitutionalAppointmentForm.layout = {
    breadcrumbs: [
        { title: 'Agenda', href: '/agenda' },
        { title: 'Compromisso institucional', href: '/agenda' },
    ],
};
