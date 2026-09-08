import { Head, Link } from '@inertiajs/react';
import {
    CalendarMarkIcon,
    ClockCircleIcon,
    MapPointIcon,
    PenIcon,
    UserRoundedIcon,
    UsersGroupRoundedIcon,
} from '@solar-icons/react/outline';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { EventStatus, OfficeEvent } from '@/types';

const statusVariants: Record<
    EventStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    planejado: 'secondary',
    confirmado: 'default',
    em_andamento: 'default',
    concluido: 'outline',
    cancelado: 'destructive',
};

const typeLabels: Record<OfficeEvent['tipo'], string> = {
    reuniao: 'Reunião',
    evento_publico: 'Evento público',
    ato_politico: 'Ato político',
    assembleia: 'Assembleia',
    outro: 'Outros',
};

const statusLabels: Record<EventStatus, string> = {
    planejado: 'Planejado',
    confirmado: 'Confirmado',
    em_andamento: 'Em andamento',
    concluido: 'Concluído',
    cancelado: 'Cancelado',
};

const formatDate = (value: string) =>
    new Intl.DateTimeFormat('pt-BR', { dateStyle: 'full' }).format(
        new Date(value),
    );
const formatTime = (value: string) =>
    new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));

export default function EventShow({
    event,
    canDelete,
}: {
    event: OfficeEvent;
    canDelete: boolean;
}) {
    const tenantUrl = useTenantUrl();
    const userParticipants = event.participantes_usuarios ?? [];
    const citizenParticipants = event.participantes_cidadaos ?? [];

    return (
        <>
            <Head title={event.titulo} />
            <PageContainer>
                <PageHeader
                    title={event.titulo}
                    description={typeLabels[event.tipo]}
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={tenantUrl(
                                        `/eventos/${event.id}/edit`,
                                    )}
                                >
                                    <PenIcon />
                                    Editar
                                </Link>
                            </Button>
                            {canDelete && (
                                <DeleteRecordButton
                                    url={tenantUrl(`/eventos/${event.id}`)}
                                    label="Excluir evento"
                                    title="Excluir evento?"
                                    description="O evento deixará de aparecer na central."
                                />
                            )}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-6">
                        <Card className="gap-4 p-5">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant={statusVariants[event.status]}>
                                    {statusLabels[event.status]}
                                </Badge>
                                <Badge variant="outline">
                                    {typeLabels[event.tipo]}
                                </Badge>
                                <Badge variant="secondary">
                                    {event.duracao === 'multiplos_dias'
                                        ? 'Vários dias'
                                        : 'Um dia'}
                                </Badge>
                            </div>
                            <div>
                                <h2 className="font-semibold">Descrição</h2>
                                <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                                    {event.descricao ??
                                        'Nenhuma descrição informada.'}
                                </p>
                            </div>
                            {event.observacoes && (
                                <div className="border-t pt-4">
                                    <h2 className="font-semibold">
                                        Observações internas
                                    </h2>
                                    <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                                        {event.observacoes}
                                    </p>
                                </div>
                            )}
                        </Card>

                        <Card className="gap-0 py-0">
                            <div className="border-b p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Participantes
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Equipe do gabinete e cidadãos vinculados ao
                                    evento.
                                </p>
                            </div>
                            <div className="p-5">
                                {userParticipants.length === 0 &&
                                citizenParticipants.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nenhum participante informado.
                                    </p>
                                ) : (
                                    <div className="grid gap-5 md:grid-cols-2">
                                        <ParticipantList
                                            title="Equipe do gabinete"
                                            participants={userParticipants.map(
                                                (participant) => ({
                                                    id: participant.id,
                                                    name: participant.name,
                                                }),
                                            )}
                                        />
                                        <div>
                                            <h3 className="text-sm font-medium">
                                                Cidadãos
                                            </h3>
                                            {citizenParticipants.length ===
                                            0 ? (
                                                <p className="text-sm text-muted-foreground">
                                                    Nenhum cidadão selecionado.
                                                </p>
                                            ) : (
                                                <ul className="mt-2 space-y-2 text-sm">
                                                    {citizenParticipants.map(
                                                        (participant) => (
                                                            <li
                                                                key={
                                                                    participant.id
                                                                }
                                                            >
                                                                <Link
                                                                    href={tenantUrl(
                                                                        `/cidadaos/${participant.id}`,
                                                                    )}
                                                                    className="hover:underline"
                                                                >
                                                                    {
                                                                        participant.nome
                                                                    }
                                                                </Link>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Card>
                    </div>

                    <aside>
                        <Card className="gap-0 py-0">
                            <div className="border-b p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Informações do evento
                                </h2>
                            </div>
                            <dl className="space-y-4 p-5">
                                {event.duracao === 'multiplos_dias' ? (
                                    <Info
                                        icon={CalendarMarkIcon}
                                        label="Período"
                                        value={`${formatDate(event.inicio_em)} até ${formatDate(event.fim_em)}`}
                                    />
                                ) : (
                                    <Info
                                        icon={CalendarMarkIcon}
                                        label="Data do evento"
                                        value={formatDate(event.inicio_em)}
                                    />
                                )}
                                <Info
                                    icon={ClockCircleIcon}
                                    label={
                                        event.duracao === 'multiplos_dias'
                                            ? 'Horário diário'
                                            : 'Horário'
                                    }
                                    value={`${formatTime(event.inicio_em)} às ${formatTime(event.fim_em)}`}
                                />
                                <Info
                                    icon={MapPointIcon}
                                    label="Local"
                                    value={event.local ?? 'Não informado'}
                                />
                                <Info
                                    icon={UserRoundedIcon}
                                    label="Responsável"
                                    value={
                                        event.responsavel?.name ??
                                        'Não informado'
                                    }
                                />
                                <Info
                                    icon={UsersGroupRoundedIcon}
                                    label="Participantes"
                                    value={`${userParticipants.length + citizenParticipants.length} selecionado(s)`}
                                />
                            </dl>
                            <p className="border-t px-5 pt-4 pb-5 text-xs text-muted-foreground">
                                Criado por{' '}
                                {event.criado_por?.name ??
                                    'usuário do gabinete'}
                                .
                            </p>
                        </Card>
                    </aside>
                </div>
            </PageContainer>
        </>
    );
}

function ParticipantList({
    title,
    participants,
}: {
    title: string;
    participants: Array<{ id: number; name: string }>;
}) {
    return (
        <div>
            <h3 className="text-sm font-medium">{title}</h3>
            {participants.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum integrante selecionado.
                </p>
            ) : (
                <ul className="mt-2 space-y-2 text-sm">
                    {participants.map((participant) => (
                        <li key={participant.id}>{participant.name}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Info({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof ClockCircleIcon;
    label: string;
    value: string;
}) {
    return (
        <div>
            <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                <Icon className="size-4" />
                {label}
            </dt>
            <dd className="mt-1 text-sm font-medium">{value}</dd>
        </div>
    );
}

EventShow.layout = (page: { event: OfficeEvent }) => ({
    breadcrumbs: [
        { title: 'Eventos', href: '/eventos' },
        { title: page.event.titulo, href: '#' },
    ],
});
