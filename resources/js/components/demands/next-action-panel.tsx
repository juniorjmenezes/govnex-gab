import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarAddIcon,
    CalendarMarkIcon,
    CheckCircleIcon,
    PenIcon,
} from '@solar-icons/react/outline';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { TableActionButton } from '@/components/common/table-action-button';
import { DatePicker } from '@/components/forms/date-picker';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { hasModule } from '@/lib/modules';
import type { Auth, Demand, DemandMember } from '@/types';

const schema = z.object({
    descricao: z.string().min(1, 'Descreva o próximo passo.'),
    data: z.string(),
    responsavel_id: z.string(),
});

type Values = z.infer<typeof schema>;

const formatDate = (date: string) =>
    new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(`${date.slice(0, 10)}T12:00:00`));

/**
 * "Próxima ação" é um único slot (descrição + data + responsável), não uma
 * lista de tarefas — definir uma nova substitui a anterior.
 */
export function NextActionPanel({
    demand,
    members,
}: {
    demand: Demand;
    members: DemandMember[];
}) {
    const tenantUrl = useTenantUrl();
    const { auth } = usePage<{ auth: Auth }>().props;
    const scheduleEnabled = hasModule(auth.modules, 'AGENDA');
    const hasPending =
        demand.proxima_acao_descricao !== null &&
        demand.proxima_acao_concluida_em === null;
    const [editing, setEditing] = useState(!hasPending);
    const {
        control,
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            descricao: demand.proxima_acao_descricao ?? '',
            data: demand.proxima_acao_data ?? '',
            responsavel_id:
                demand.proxima_acao_responsavel_id?.toString() ??
                demand.responsavel_id?.toString() ??
                '',
        },
    });

    const submit = (values: Values) => {
        router.post(tenantUrl(`/demandas/${demand.id}/proxima-acao`), values, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const complete = () => {
        router.patch(
            tenantUrl(`/demandas/${demand.id}/proxima-acao/concluir`),
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const scheduleUrl = () => {
        // Prefixados com "criar_" para não colidir com os filtros da própria
        // listagem da agenda (responsavel_id, status, tipo, date...).
        const params = new URLSearchParams({
            nova_reuniao: '1',
            criar_demanda_id: String(demand.id),
            criar_titulo: demand.proxima_acao_descricao ?? '',
        });

        if (demand.proxima_acao_data) {
            params.set('criar_data', demand.proxima_acao_data.slice(0, 10));
        }

        if (demand.proxima_acao_responsavel_id) {
            params.set(
                'criar_responsavel_id',
                String(demand.proxima_acao_responsavel_id),
            );
        }

        return tenantUrl(`/agenda?${params.toString()}`);
    };

    if (!editing && hasPending) {
        return (
            <div className="p-4">
                <div className="rounded-2xl border bg-muted/40 p-4">
                    <p className="text-sm font-medium">
                        {demand.proxima_acao_descricao}
                    </p>
                    {demand.proxima_acao_data && (
                        <p
                            className={
                                demand.proxima_acao_atrasada
                                    ? 'mt-1 flex items-center gap-1 text-xs font-semibold text-destructive'
                                    : 'mt-1 flex items-center gap-1 text-xs text-muted-foreground'
                            }
                        >
                            <CalendarMarkIcon className="size-3.5" />
                            {formatDate(demand.proxima_acao_data)}
                            {demand.proxima_acao_atrasada && ' · atrasada'}
                        </p>
                    )}
                    {demand.proxima_acao_responsavel?.name && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Responsável: {demand.proxima_acao_responsavel.name}
                        </p>
                    )}
                    <div className="mt-3 flex justify-end gap-2">
                        <TableActionButton
                            type="button"
                            label="Redefinir próxima ação"
                            onClick={() => setEditing(true)}
                        >
                            <PenIcon />
                        </TableActionButton>
                        {scheduleEnabled && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={scheduleUrl()}>
                                    <CalendarAddIcon />
                                    Adicionar à agenda
                                </Link>
                            </Button>
                        )}
                        <Button size="sm" onClick={complete}>
                            <CheckCircleIcon />
                            Concluir
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={handleSubmit(submit)} className="space-y-3 p-5">
            <div className="space-y-1">
                <Label htmlFor="proxima_acao_descricao">Descrição</Label>
                <Input
                    id="proxima_acao_descricao"
                    placeholder="O que precisa acontecer?"
                    aria-invalid={Boolean(errors.descricao)}
                    {...register('descricao')}
                />
                <FieldError message={errors.descricao?.message} />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label htmlFor="proxima_acao_data">Data</Label>
                    <Controller
                        control={control}
                        name="data"
                        render={({ field }) => (
                            <DatePicker
                                id="proxima_acao_data"
                                value={field.value}
                                onChange={field.onChange}
                            />
                        )}
                    />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="proxima_acao_responsavel_id">
                        Responsável
                    </Label>
                    <Controller
                        control={control}
                        name="responsavel_id"
                        render={({ field }) => (
                            <AppSelect
                                id="proxima_acao_responsavel_id"
                                value={field.value}
                                onValueChange={field.onChange}
                                options={members.map((member) => ({
                                    value: member.id.toString(),
                                    label: member.name,
                                }))}
                                emptyLabel="Não definido"
                            />
                        )}
                    />
                </div>
            </div>
            <div className="flex justify-end gap-2">
                {hasPending && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setEditing(false)}
                    >
                        Cancelar
                    </Button>
                )}
                <Button type="submit" disabled={isSubmitting}>
                    Salvar
                </Button>
            </div>
        </form>
    );
}
