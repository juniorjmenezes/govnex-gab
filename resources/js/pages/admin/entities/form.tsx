import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import { AddressFields } from '@/components/forms/address-fields';
import { FieldError } from '@/components/forms/field-error';
import {
    Buildings2Icon,
    Buildings3Icon,
    BuildingsIcon,
    DangerCircleIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EntidadeType = 'GABINETE_INDEPENDENTE' | 'CAMARA_MUNICIPAL' | 'PREFEITURA';

type TypeOption = {
    value: EntidadeType;
    label: string;
    description: string;
};

type Props = { types: TypeOption[] };

const schema = z.object({
    tipo: z.enum(['GABINETE_INDEPENDENTE', 'CAMARA_MUNICIPAL', 'PREFEITURA']),
    nome: z.string().min(2, 'Informe o nome da entidade.'),
    municipio: z.string().min(2, 'Informe o município.'),
    estado: z.string().length(2, 'Selecione a UF.'),
    timezone: z.string().min(1, 'Informe o fuso horário.'),
    endereco: z.string(),
    numero: z.string(),
    complemento: z.string(),
    cep: z.string(),
});

type Values = z.infer<typeof schema>;

const icons = {
    GABINETE_INDEPENDENTE: BuildingsIcon,
    CAMARA_MUNICIPAL: Buildings2Icon,
    PREFEITURA: Buildings3Icon,
};

export default function EntityForm({ types }: Props) {
    const [isSaving, setIsSaving] = useState(false);
    const {
        control,
        register,
        setValue,
        setError,
        handleSubmit,
        formState: { errors },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            tipo: types[0]?.value ?? 'GABINETE_INDEPENDENTE',
            nome: '',
            municipio: '',
            estado: '',
            timezone: 'America/Sao_Paulo',
            endereco: '',
            numero: '',
            complemento: '',
            cep: '',
        },
    });
    const selectedType = useWatch({ control, name: 'tipo' });
    const validationMessages = Object.values(errors)
        .map((error) => error?.message)
        .filter((message): message is string => typeof message === 'string');

    const submit = (values: Values) => {
        setIsSaving(true);
        router.post('/admin/entidades', values, {
            preserveScroll: true,
            onError: (items) => {
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                );
            },
            onFinish: () => setIsSaving(false),
        });
    };

    return (
        <>
            <Head title="Nova entidade" />
            <PageContainer>
                <PageHeader
                    title="Nova entidade"
                    description="Cadastre a organização primeiro. Na próxima etapa, você adicionará o primeiro gabinete."
                />

                <form onSubmit={handleSubmit(submit)} className="space-y-6">
                    {validationMessages.length > 0 && (
                        <Alert variant="destructive" role="alert">
                            <DangerCircleIcon />
                            <AlertTitle>Revise os dados informados</AlertTitle>
                            <AlertDescription>
                                {validationMessages[0]}
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card className="gap-0 py-0">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Qual organização será cadastrada?
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Essa escolha define os tipos de gabinete que
                                poderão ser adicionados depois.
                            </p>
                        </div>
                        <div className="grid gap-3 p-5 md:grid-cols-3">
                            {types.map((type) => {
                                const Icon = icons[type.value];
                                const selected = selectedType === type.value;

                                return (
                                    <button
                                        key={type.value}
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() =>
                                            setValue('tipo', type.value, {
                                                shouldValidate: true,
                                            })
                                        }
                                        className={`border p-4 text-left transition-colors ${
                                            selected
                                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                : 'border-border hover:bg-muted/50'
                                        }`}
                                    >
                                        <Icon
                                            className="mb-3 size-5"
                                            aria-hidden="true"
                                        />
                                        <span className="block font-medium">
                                            {type.label}
                                        </span>
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            {type.description}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </Card>

                    <Card className="gap-0 py-0">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Identificação e localização
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Os gabinetes desta entidade usarão o mesmo
                                município, UF e fuso horário.
                            </p>
                        </div>
                        <div className="space-y-5 p-5">
                            <div className="space-y-1">
                                <Label htmlFor="nome">Nome da entidade</Label>
                                <Input
                                    id="nome"
                                    {...register('nome')}
                                    placeholder={
                                        selectedType === 'CAMARA_MUNICIPAL'
                                            ? 'Câmara Municipal de…'
                                            : selectedType === 'PREFEITURA'
                                              ? 'Prefeitura de…'
                                              : 'Nome institucional do gabinete'
                                    }
                                />
                                <FieldError message={errors.nome?.message} />
                            </div>
                            <AddressFields
                                control={control}
                                register={register}
                                setValue={setValue}
                                errors={errors}
                                locationOnly
                            />
                            <div className="space-y-1">
                                <Label htmlFor="timezone">Fuso horário</Label>
                                <Input
                                    id="timezone"
                                    {...register('timezone')}
                                />
                                <FieldError
                                    message={errors.timezone?.message}
                                />
                            </div>
                        </div>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get('/admin/gabinetes')}
                        >
                            Cancelar
                        </Button>
                        <Button disabled={isSaving}>
                            {isSaving
                                ? 'Salvando…'
                                : 'Criar entidade e continuar'}
                        </Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

EntityForm.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        { title: 'Nova entidade', href: '/admin/entidades/nova' },
    ],
};
