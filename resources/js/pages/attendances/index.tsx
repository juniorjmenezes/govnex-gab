import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { VoterMark } from '@/components/citizens/voter-mark';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { DatePicker } from '@/components/forms/date-picker';
import {
    AddIcon,
    CloseIcon,
    EyeIcon,
    HandShakeIcon,
    MagnifierIcon,
    PenIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { formatShortDateTime } from '@/lib/dates';
import { hasModule } from '@/lib/modules';
import { preservedListParams } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { AttendanceIndexProps, Auth } from '@/types';

const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('pt-BR', {
              dateStyle: 'short',
              timeZone: 'UTC',
          }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`))
        : 'Não informado';

export default function AttendancesIndex({
    attendances,
    filters,
    members,
    canDelete,
}: AttendanceIndexProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const tenantUrl = useTenantUrl();
    const demandsEnabled = hasModule(auth.modules, 'DEMANDAS');
    const [query, setQuery] = useState(filters.q);
    const [attendantId, setAttendantId] = useState(
        filters.atendente_id?.toString() ?? '',
    );
    const [from, setFrom] = useState(filters.de);
    const [to, setTo] = useState(filters.ate);
    const [returnOnly, setReturnOnly] = useState(filters.retorno);

    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                tenantUrl('/atendimentos'),
                {
                    ...(query && { q: query }),
                    ...(attendantId && { atendente_id: attendantId }),
                    ...(from && { de: from }),
                    ...(to && { ate: to }),
                    ...(returnOnly && { retorno: '1' }),
                    ...preservedListParams(),
                },
                { preserveState: true, replace: true },
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [query, attendantId, from, to, returnOnly, tenantUrl]);

    const hasFilters = Boolean(
        query || attendantId || from || to || returnOnly,
    );
    const clear = () => {
        setQuery('');
        setAttendantId('');
        setFrom('');
        setTo('');
        setReturnOnly(false);
        router.get(tenantUrl('/atendimentos'), {}, { replace: true });
    };

    return (
        <>
            <Head title="Atendimentos presenciais" />
            <PageContainer>
                <PageHeader
                    title="Atendimentos presenciais"
                    description="Histórico das visitas realizadas no gabinete e das providências registradas."
                    actions={
                        <Button asChild>
                            <Link href={tenantUrl('/atendimentos/create')}>
                                <AddIcon />
                                Novo atendimento
                            </Link>
                        </Button>
                    }
                />

                <form
                    onSubmit={(event) => event.preventDefault()}
                    className={cn(surfaceClasses, 'flex flex-col gap-3 p-4')}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative min-w-56 flex-1">
                            <MagnifierIcon className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                className="pl-9"
                                placeholder="Cidadão, assunto ou relato"
                                aria-label="Buscar atendimentos"
                            />
                        </div>
                        <AppSelect
                            value={attendantId}
                            onValueChange={setAttendantId}
                            emptyLabel="Todos os atendentes"
                            options={members.map((member) => ({
                                value: member.id.toString(),
                                label: member.name,
                            }))}
                            aria-label="Filtrar por atendente"
                            className="w-52"
                        />
                        <DatePicker
                            value={from}
                            onChange={setFrom}
                            aria-label="Atendimentos desde"
                            className="w-44"
                        />
                        <DatePicker
                            value={to}
                            onChange={setTo}
                            aria-label="Atendimentos até"
                            className="w-44"
                        />
                        {hasFilters && (
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={clear}
                                aria-label="Limpar filtros"
                            >
                                <CloseIcon />
                            </Button>
                        )}
                    </div>
                    <Label>
                        <Switch
                            checked={returnOnly}
                            onCheckedChange={setReturnOnly}
                            aria-label="Somente atendimentos que necessitam retorno"
                        />
                        Somente os que necessitam retorno
                    </Label>
                </form>

                <Surface as="section" className="overflow-hidden">
                    {attendances.data.length === 0 ? (
                        <EmptyState
                            icon={HandShakeIcon}
                            title="Nenhum atendimento encontrado"
                            description="Registre o primeiro atendimento presencial ou ajuste os filtros."
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Data</TableHead>
                                        <TableHead>Cidadão</TableHead>
                                        <TableHead>Assunto</TableHead>
                                        <TableHead>Atendente</TableHead>
                                        <TableHead>Retorno</TableHead>
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {attendances.data.map((attendance) => (
                                        <TableRow key={attendance.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatShortDateTime(
                                                    attendance.atendido_em,
                                                )}
                                                {attendance.duracao_minutos && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {
                                                            attendance.duracao_minutos
                                                        }{' '}
                                                        min
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <span className="inline-flex items-center gap-2">
                                                    <Link
                                                        href={tenantUrl(
                                                            `/cidadaos/${attendance.cidadao.id}`,
                                                        )}
                                                        className="font-normal hover:underline"
                                                    >
                                                        {
                                                            attendance.cidadao
                                                                .nome
                                                        }
                                                    </Link>
                                                    <VoterMark
                                                        voter={
                                                            attendance.cidadao
                                                                .eleitor
                                                        }
                                                    />
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={tenantUrl(
                                                        `/atendimentos/${attendance.id}`,
                                                    )}
                                                    className="font-normal hover:underline"
                                                >
                                                    {attendance.assunto}
                                                </Link>
                                                {attendance.demanda &&
                                                    demandsEnabled && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {
                                                                attendance
                                                                    .demanda
                                                                    .protocolo
                                                            }
                                                        </p>
                                                    )}
                                            </TableCell>
                                            <TableCell>
                                                {attendance.atendente?.name ??
                                                    'Usuário removido'}
                                            </TableCell>
                                            <TableCell>
                                                {attendance.requer_retorno ? (
                                                    <Badge variant="outline">
                                                        {formatDate(
                                                            attendance.retorno_previsto_em,
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Não
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <TableActionButton
                                                        asChild
                                                        label={`Visualizar ${attendance.assunto}`}
                                                    >
                                                        <Link
                                                            href={tenantUrl(
                                                                `/atendimentos/${attendance.id}`,
                                                            )}
                                                        >
                                                            <EyeIcon aria-hidden="true" />
                                                        </Link>
                                                    </TableActionButton>
                                                    <TableActionButton
                                                        asChild
                                                        label={`Editar ${attendance.assunto}`}
                                                    >
                                                        <Link
                                                            href={tenantUrl(
                                                                `/atendimentos/${attendance.id}/edit`,
                                                            )}
                                                        >
                                                            <PenIcon aria-hidden="true" />
                                                        </Link>
                                                    </TableActionButton>
                                                    {canDelete && (
                                                        <DeleteRecordButton
                                                            url={tenantUrl(
                                                                `/atendimentos/${attendance.id}`,
                                                            )}
                                                            label={`Excluir ${attendance.assunto}`}
                                                            title="Excluir atendimento?"
                                                            description="O registro deixará de aparecer no histórico do gabinete."
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <PaginationLinks
                                pagination={attendances}
                                label="atendimento(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

AttendancesIndex.layout = {
    breadcrumbs: [{ title: 'Atendimentos', href: '/atendimentos' }],
};
