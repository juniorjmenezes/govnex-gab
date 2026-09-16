import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { ColorPicker } from '@/components/forms/color-picker';
import { FieldError } from '@/components/forms/field-error';
import { PaletteIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type PartyColor = {
    id: number;
    sigla: string;
    cor: string;
};

const schema = z.object({
    sigla: z.string().min(1, 'Informe a sigla.').max(30),
    cor: z.string().regex(/^#[0-9A-Fa-f]{6}$/, 'Use uma cor hexadecimal.'),
});
type Values = z.infer<typeof schema>;

const DEFAULT_COLOR = '#2563EB';

function PartyColorRow({ party }: { party: PartyColor }) {
    const [color, setColor] = useState(party.cor);
    const [pending, setPending] = useState(false);
    const dirty = color.toUpperCase() !== party.cor.toUpperCase();

    const save = () => {
        setPending(true);
        router.patch(
            `/admin/cores-partidos/${party.id}`,
            { sigla: party.sigla, cor: color },
            { preserveScroll: true, onFinish: () => setPending(false) },
        );
    };

    return (
        <TableRow>
            <TableCell>{party.sigla}</TableCell>
            <TableCell className="w-56">
                <ColorPicker
                    value={color}
                    onChange={setColor}
                    aria-label={`Cor de ${party.sigla}`}
                />
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end gap-2">
                    {/* Só aparece com a cor alterada e ainda não salva: é o
                        aviso da pendência, por isso mantém texto e destaque
                        em vez de virar mais um ícone na coluna. */}
                    {dirty && (
                        <Button size="sm" onClick={save} disabled={pending}>
                            Salvar
                        </Button>
                    )}
                    <DeleteRecordButton
                        url={`/admin/cores-partidos/${party.id}`}
                        label={`Remover cor de ${party.sigla}`}
                        title={`Remover a cor de ${party.sigla}?`}
                        description="O partido volta a aparecer sem cor definida nos gráficos e pesquisas até que uma nova cor seja cadastrada."
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}

export default function PartyColors({ parties }: { parties: PartyColor[] }) {
    const {
        register,
        control,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { sigla: '', cor: DEFAULT_COLOR },
    });

    const submit = (values: Values) =>
        router.post('/admin/cores-partidos', values, {
            onSuccess: () => reset({ sigla: '', cor: DEFAULT_COLOR }),
            onError: (items) =>
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });

    return (
        <>
            <Head title="Cores de partidos" />
            <PageContainer>
                <PageHeader
                    title="Cores de partidos"
                    description="Defina a cor de cada partido para identificá-los de forma consistente nos gráficos de pesquisas eleitorais em todos os gabinetes."
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Novo partido</SurfaceTitle>
                    </SurfaceHeader>
                    <form
                        noValidate
                        onSubmit={handleSubmit(submit)}
                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start"
                    >
                        <div className="w-full min-w-0 space-y-1 sm:flex-1">
                            <Input
                                aria-label="Sigla"
                                className="uppercase"
                                placeholder="Ex.: PT, PL, PSDB"
                                {...register('sigla')}
                            />
                            <FieldError message={errors.sigla?.message} />
                        </div>
                        <div className="w-full min-w-0 space-y-1 sm:flex-1">
                            <Controller
                                control={control}
                                name="cor"
                                render={({ field }) => (
                                    <ColorPicker
                                        value={field.value}
                                        onChange={field.onChange}
                                        aria-label="Cor do partido"
                                    />
                                )}
                            />
                            <FieldError message={errors.cor?.message} />
                        </div>
                        <Button
                            className="w-full shrink-0 sm:w-auto"
                            disabled={isSubmitting}
                        >
                            Cadastrar cor
                        </Button>
                    </form>
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    {parties.length === 0 ? (
                        <EmptyState
                            icon={PaletteIcon}
                            title="Nenhuma cor cadastrada"
                            description="Cadastre a sigla e a cor do primeiro partido acima. Sem cor definida, o partido aparece neutro nos gráficos e pesquisas."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Sigla</TableHead>
                                    <TableHead>Cor</TableHead>
                                    <TableHead className="text-right">
                                        Ações
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {parties.map((party) => (
                                    <PartyColorRow
                                        key={party.id}
                                        party={party}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

PartyColors.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Cores de partidos', href: '/admin/cores-partidos' },
    ],
};
