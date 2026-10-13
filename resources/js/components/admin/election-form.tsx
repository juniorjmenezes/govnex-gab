import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { DatePicker } from '@/components/forms/date-picker';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AdminElection, ElectionStatus, ElectionType } from '@/types';

export const electionTypeLabels: Record<ElectionType, string> = {
    geral: 'Eleição geral',
    municipal: 'Eleição municipal',
};

export const electionStatusBadges: Record<
    ElectionStatus,
    { label: string; variant: 'info' | 'success' | 'danger' }
> = {
    programada: { label: 'Programada', variant: 'info' },
    concluida: { label: 'Concluída', variant: 'success' },
    cancelada: { label: 'Cancelada', variant: 'danger' },
};

export function formatElectionDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-');

    return `${day}/${month}/${year}`;
}

const typeOptions: { value: ElectionType; label: string }[] = [
    { value: 'geral', label: 'Eleições gerais' },
    { value: 'municipal', label: 'Eleições municipais' },
];

const statusOptions: { value: ElectionStatus; label: string }[] = [
    { value: 'programada', label: 'Programada' },
    { value: 'concluida', label: 'Concluída' },
    { value: 'cancelada', label: 'Cancelada' },
];

const schema = z
    .object({
        ano: z
            .string()
            .regex(/^\d{4}$/, 'Informe um ano com 4 dígitos.')
            .refine(
                (value) => Number(value) >= 1990 && Number(value) <= 2100,
                'Informe um ano entre 1990 e 2100.',
            ),
        tipo: z.enum(['geral', 'municipal'], { error: 'Selecione o tipo.' }),
        nome: z.string().min(1, 'Informe o nome da eleição.').max(255),
        codigo_tse: z.string().max(30).optional().or(z.literal('')),
        primeiro_turno_em: z.string().min(1, 'Informe a data do 1º turno.'),
        segundo_turno_em: z.string().optional().or(z.literal('')),
        situacao: z.enum(['programada', 'concluida', 'cancelada']),
    })
    .refine(
        (values) =>
            !values.segundo_turno_em ||
            values.segundo_turno_em > values.primeiro_turno_em,
        {
            message: 'O 2º turno deve ser depois do 1º turno.',
            path: ['segundo_turno_em'],
        },
    );
type Values = z.infer<typeof schema>;

function toValues(election: AdminElection | null): Values {
    return election
        ? {
              ano: String(election.ano),
              tipo: election.tipo,
              nome: election.nome,
              codigo_tse: election.codigo_tse ?? '',
              primeiro_turno_em: election.primeiro_turno_em,
              segundo_turno_em: election.segundo_turno_em ?? '',
              situacao: election.situacao,
          }
        : {
              ano: String(new Date().getFullYear()),
              tipo: 'geral',
              nome: '',
              codigo_tse: '',
              primeiro_turno_em: '',
              segundo_turno_em: '',
              situacao: 'programada',
          };
}

/**
 * Campos de uma eleição. Sem `election`, cadastra uma nova (o backend leva à
 * tela dela); com `election`, edita aquela.
 */
export function ElectionForm({
    election = null,
}: {
    election?: AdminElection | null;
}) {
    const {
        control,
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: toValues(election),
    });

    const submit = (values: Values) => {
        const payload = {
            ...values,
            ano: Number(values.ano),
            codigo_tse: values.codigo_tse || null,
            segundo_turno_em: values.segundo_turno_em || null,
        };
        const onError = (items: Record<string, string>) =>
            Object.entries(items).forEach(([key, message]) =>
                setError(key as keyof Values, { message }),
            );

        if (election) {
            router.patch(`/admin/eleicoes/${election.id}`, payload, {
                preserveScroll: true,
                onError,
                onSuccess: (page) => {
                    const updated = (page.props as { election?: AdminElection })
                        .election;

                    reset(toValues(updated ?? election));
                },
            });

            return;
        }

        router.post('/admin/eleicoes', payload, { onError });
    };

    const idPrefix = election ? `eleicao-${election.id}` : 'nova-eleicao';
    const id = (name: keyof Values) => `${idPrefix}-${name}`;

    return (
        <form
            noValidate
            onSubmit={handleSubmit(submit)}
            className="grid gap-4 p-4"
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="space-y-1">
                    <Label htmlFor={id('ano')}>Ano</Label>
                    <Input
                        id={id('ano')}
                        type="number"
                        inputMode="numeric"
                        {...register('ano')}
                        aria-invalid={Boolean(errors.ano)}
                    />
                    <FieldError message={errors.ano?.message} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('tipo')}>Tipo</Label>
                    <Controller
                        control={control}
                        name="tipo"
                        render={({ field }) => (
                            <AppSelect
                                id={id('tipo')}
                                value={field.value}
                                onValueChange={field.onChange}
                                options={typeOptions}
                                clearable={false}
                                aria-invalid={Boolean(errors.tipo)}
                            />
                        )}
                    />
                    <FieldError message={errors.tipo?.message} />
                </div>
                <div className="space-y-1 sm:col-span-2 lg:col-span-2">
                    <Label htmlFor={id('nome')}>Nome</Label>
                    <Input
                        id={id('nome')}
                        placeholder="Ex.: Eleições Gerais 2030"
                        {...register('nome')}
                        aria-invalid={Boolean(errors.nome)}
                    />
                    <FieldError message={errors.nome?.message} />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="space-y-1">
                    <Label htmlFor={id('primeiro_turno_em')}>1º turno</Label>
                    <Controller
                        control={control}
                        name="primeiro_turno_em"
                        render={({ field }) => (
                            <DatePicker
                                id={id('primeiro_turno_em')}
                                value={field.value}
                                onChange={field.onChange}
                                aria-invalid={Boolean(errors.primeiro_turno_em)}
                            />
                        )}
                    />
                    <FieldError message={errors.primeiro_turno_em?.message} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('segundo_turno_em')}>2º turno</Label>
                    <Controller
                        control={control}
                        name="segundo_turno_em"
                        render={({ field }) => (
                            <DatePicker
                                id={id('segundo_turno_em')}
                                value={field.value ?? ''}
                                onChange={field.onChange}
                                aria-invalid={Boolean(errors.segundo_turno_em)}
                            />
                        )}
                    />
                    <FieldError message={errors.segundo_turno_em?.message} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('codigo_tse')}>Código TSE</Label>
                    <Input
                        id={id('codigo_tse')}
                        placeholder="Opcional"
                        {...register('codigo_tse')}
                        aria-invalid={Boolean(errors.codigo_tse)}
                    />
                    <FieldError message={errors.codigo_tse?.message} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('situacao')}>Situação</Label>
                    <Controller
                        control={control}
                        name="situacao"
                        render={({ field }) => (
                            <AppSelect
                                id={id('situacao')}
                                value={field.value}
                                onValueChange={field.onChange}
                                options={statusOptions}
                                clearable={false}
                                aria-invalid={Boolean(errors.situacao)}
                            />
                        )}
                    />
                    <FieldError message={errors.situacao?.message} />
                </div>
            </div>

            <div className="flex justify-end gap-2">
                <Button disabled={isSubmitting}>
                    {election ? 'Salvar alterações' : 'Cadastrar eleição'}
                </Button>
            </div>
        </form>
    );
}
