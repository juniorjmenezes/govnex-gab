import { Head, Link, usePage } from '@inertiajs/react';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import {
    CalendarMarkIcon,
    ClipboardListIcon,
    ClockCircleIcon,
    HandShakeIcon,
    PenIcon,
    RestartIcon,
    UserRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { maskPhone } from '@/lib/masks';
import { hasModule } from '@/lib/modules';
import type { Attendance, Auth } from '@/types';

const formatDateTime = (value: string) =>
    new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(value));

const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('pt-BR', {
              dateStyle: 'long',
              timeZone: 'UTC',
          }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`))
        : 'Não informado';

export default function AttendanceShow({
    attendance,
    canDelete,
}: {
    attendance: Attendance;
    canDelete: boolean;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const tenantUrl = useTenantUrl();
    const demandsEnabled = hasModule(auth.modules, 'DEMANDAS');

    return (
        <>
            <Head title={attendance.assunto} />
            <PageContainer>
                <PageHeader
                    title={attendance.assunto}
                    description={`${attendance.cidadao.nome} • ${formatDateTime(attendance.atendido_em)}`}
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={tenantUrl(
                                        `/atendimentos/${attendance.id}/edit`,
                                    )}
                                >
                                    <PenIcon />
                                    Editar
                                </Link>
                            </Button>
                            {canDelete && (
                                <DeleteRecordButton
                                    url={tenantUrl(
                                        `/atendimentos/${attendance.id}`,
                                    )}
                                    label="Excluir atendimento"
                                    title="Excluir atendimento?"
                                    description="O registro deixará de aparecer no histórico do gabinete."
                                />
                            )}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-6">
                        <Card className="gap-5 p-5">
                            <div>
                                <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-foreground uppercase">
                                    <HandShakeIcon className="size-4 text-primary" />
                                    Relato do atendimento
                                </h2>
                                <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                                    {attendance.relato}
                                </p>
                            </div>
                            <div className="border-t pt-5">
                                <h2 className="font-semibold">
                                    Providências e orientações
                                </h2>
                                <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                                    {attendance.providencias ??
                                        'Nenhuma providência registrada.'}
                                </p>
                            </div>
                        </Card>

                        {attendance.demanda && demandsEnabled && (
                            <Card className="gap-0 py-0">
                                <div className="border-b p-4">
                                    <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-foreground uppercase">
                                        <ClipboardListIcon className="size-4 text-primary" />
                                        Demanda relacionada
                                    </h2>
                                </div>
                                <div className="p-5">
                                    <Link
                                        href={tenantUrl(
                                            `/demandas/${attendance.demanda.id}`,
                                        )}
                                        className="rounded-xl border p-4 transition-colors hover:bg-muted"
                                    >
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {attendance.demanda.protocolo}
                                        </span>
                                        <strong className="mt-1 block text-sm">
                                            {attendance.demanda.titulo}
                                        </strong>
                                    </Link>
                                </div>
                            </Card>
                        )}
                    </div>

                    <aside className="space-y-6">
                        <Card className="gap-0 py-0">
                            <div className="border-b p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Dados do registro
                                </h2>
                            </div>
                            <dl className="space-y-4 p-5">
                                <Info
                                    icon={CalendarMarkIcon}
                                    label="Data e hora"
                                    value={formatDateTime(
                                        attendance.atendido_em,
                                    )}
                                />
                                <Info
                                    icon={ClockCircleIcon}
                                    label="Duração"
                                    value={
                                        attendance.duracao_minutos
                                            ? `${attendance.duracao_minutos} minutos`
                                            : 'Não informada'
                                    }
                                />
                                <Info
                                    icon={UserRoundedIcon}
                                    label="Atendente"
                                    value={
                                        attendance.atendente?.name ??
                                        'Usuário removido'
                                    }
                                />
                            </dl>
                            <p className="border-t px-5 pt-4 pb-5 text-xs text-muted-foreground">
                                Registrado por{' '}
                                {attendance.criado_por?.name ??
                                    'usuário do gabinete'}
                                .
                            </p>
                        </Card>

                        <Card className="gap-0 py-0">
                            <div className="flex items-center justify-between gap-2 border-b p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Cidadão
                                </h2>
                                {attendance.cidadao.eleitor && (
                                    <Badge>Eleitor</Badge>
                                )}
                            </div>
                            <div className="p-5">
                                <Link
                                    href={tenantUrl(
                                        `/cidadaos/${attendance.cidadao.id}`,
                                    )}
                                    className="font-medium hover:underline"
                                >
                                    {attendance.cidadao.nome}
                                </Link>
                                <p className="text-sm text-muted-foreground">
                                    {attendance.cidadao.whatsapp ||
                                    attendance.cidadao.telefone
                                        ? maskPhone(
                                              attendance.cidadao.whatsapp ??
                                                  attendance.cidadao.telefone,
                                          )
                                        : 'Contato não informado'}
                                </p>
                            </div>
                        </Card>

                        {attendance.requer_retorno && (
                            <Card className="gap-0 border-amber-300 bg-amber-50 py-0 text-amber-950 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                <div className="border-b border-amber-300 p-4 dark:border-amber-900">
                                    <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-foreground uppercase">
                                        <RestartIcon className="size-4" />
                                        Retorno necessário
                                    </h2>
                                </div>
                                <p className="p-5 text-sm">
                                    Previsão:{' '}
                                    {formatDate(attendance.retorno_previsto_em)}
                                </p>
                            </Card>
                        )}
                    </aside>
                </div>
            </PageContainer>
        </>
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

AttendanceShow.layout = (page: { attendance: Attendance }) => ({
    breadcrumbs: [
        { title: 'Atendimentos', href: '/atendimentos' },
        { title: page.attendance.assunto, href: '#' },
    ],
});
