import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { ColorPicker } from '@/components/forms/color-picker';
import { FieldError } from '@/components/forms/field-error';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { surfaceClasses } from '@/components/ui/surface';
import { cn } from '@/lib/utils';

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
        <div className="flex flex-wrap items-center gap-3 p-4">
            <span className="w-20 shrink-0 font-heading text-sm font-semibold">
                {party.sigla}
            </span>
            <div className="w-44">
                <ColorPicker
                    value={color}
                    onChange={setColor}
                    aria-label={`Cor de ${party.sigla}`}
                />
            </div>
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
                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <Card className="gap-0 py-0">
                        {parties.length === 0 ? (
                            <p className="p-6 text-sm text-muted-foreground">
                                Nenhuma cor cadastrada ainda.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {parties.map((party) => (
                                    <PartyColorRow
                                        key={party.id}
                                        party={party}
                                    />
                                ))}
                            </div>
                        )}
                    </Card>
                    <form
                        onSubmit={handleSubmit(submit)}
                        className={cn(surfaceClasses, 'overflow-hidden')}
                    >
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Novo partido
                            </h2>
                        </div>
                        <div className="space-y-4 p-5">
                            <div className="space-y-1">
                                <Label htmlFor="party-sigla">Sigla</Label>
                                <Input
                                    id="party-sigla"
                                    className="uppercase"
                                    placeholder="Ex.: PT, PL, PSDB"
                                    {...register('sigla')}
                                />
                                <FieldError message={errors.sigla?.message} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="party-color">Cor</Label>
                                <Controller
                                    control={control}
                                    name="cor"
                                    render={({ field }) => (
                                        <ColorPicker
                                            id="party-color"
                                            value={field.value}
                                            onChange={field.onChange}
                                            aria-label="Cor do partido"
                                        />
                                    )}
                                />
                                <FieldError message={errors.cor?.message} />
                            </div>
                            <Button className="w-full" disabled={isSubmitting}>
                                Cadastrar cor
                            </Button>
                        </div>
                    </form>
                </div>
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
