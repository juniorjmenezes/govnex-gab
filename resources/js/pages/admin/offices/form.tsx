import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import {
    getOfficeModuleSelectionErrors,
    OfficeModuleSelector,
    toggleOfficeModule,
} from '@/components/admin/office-module-selector';
import { AddressFields } from '@/components/forms/address-fields';
import { FieldError } from '@/components/forms/field-error';
import { FieldLabel } from '@/components/forms/field-label';
import { AddIcon, DangerCircleIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { applyMask } from '@/lib/masks';
import type { GabineteModuleCode, GabineteModuleDefinition } from '@/types';

type EntidadeTypeCode =
    'GABINETE_INDEPENDENTE' | 'CAMARA_MUNICIPAL' | 'PREFEITURA';
type GabineteTypeCode =
    | 'GABINETE_INDEPENDENTE'
    | 'GABINETE_VEREADOR'
    | 'GABINETE_PREFEITO'
    | 'SECRETARIA'
    | 'SETOR_ADMINISTRATIVO';

type GabineteTypeOption = {
    value: GabineteTypeCode;
    label: string;
    leader_label: string;
};
type EntidadeOption = {
    id: number;
    name: string;
    type: EntidadeTypeCode;
    type_label: string;
    city: string;
    state: string;
    timezone: string;
    gabinetes_count: number;
    tipos_gabinete: GabineteTypeOption[];
};
type EntidadeSummary = EntidadeOption | null;
type Office = {
    id: number;
    nome: string;
    tipo_gabinete: GabineteTypeCode;
    vereador_nome: string | null;
    numero_eleitoral: string | null;
    municipio: string;
    estado: string;
    timezone: string | null;
    telefone: string | null;
    email: string | null;
    endereco: string | null;
    numero: string | null;
    complemento: string | null;
    bairro: string | null;
    cep: string | null;
};
type Responsible = { name: string; email: string } | null;

type Props = {
    office: Office | null;
    responsible: Responsible;
    entidade: EntidadeSummary;
    entidades: EntidadeOption[];
    moduleCatalog: GabineteModuleDefinition[];
    modules: GabineteModuleCode[];
};

const initialPassword = z
    .string()
    .min(12, 'Use ao menos 12 caracteres.')
    .regex(/[a-z]/, 'Inclua ao menos uma letra minúscula.')
    .regex(/[A-Z]/, 'Inclua ao menos uma letra maiúscula.')
    .regex(/\d/, 'Inclua ao menos um número.')
    .regex(/[^A-Za-z0-9]/, 'Inclua ao menos um símbolo.');

const schema = z
    .object({
        entidade_id: z.string(),
        tipo_gabinete: z.enum([
            'GABINETE_INDEPENDENTE',
            'GABINETE_VEREADOR',
            'GABINETE_PREFEITO',
            'SECRETARIA',
            'SETOR_ADMINISTRATIVO',
        ]),
        nome: z.string().min(2, 'Informe o nome do gabinete.'),
        vereador_nome: z.string().min(2, 'Informe o nome do responsável.'),
        numero_eleitoral: z.string().regex(/^\d*$/, 'Informe somente números.'),
        municipio: z.string().min(2, 'Informe o município.'),
        estado: z.string().length(2, 'Use a sigla com 2 letras.'),
        endereco: z.string(),
        numero: z.string(),
        complemento: z.string(),
        bairro: z.string(),
        cep: z.string(),
        timezone: z.string().min(1, 'Informe o fuso horário.'),
        telefone: z.string(),
        email: z.union([z.literal(''), z.email('E-mail inválido.')]),
        responsavel_nome: z.string().min(2, 'Informe o responsável.'),
        responsavel_email: z.email('E-mail inválido.'),
        responsavel_password: z.union([z.literal(''), initialPassword]),
        responsavel_password_confirmation: z.string(),
        modules: z.array(
            z.enum([
                'RELACIONAMENTO',
                'DEMANDAS',
                'ATENDIMENTOS',
                'AGENDA',
                'EVENTOS',
                'POLITICA',
                'RELATORIOS',
                'WHATSAPP',
                'BASE_CONHECIMENTO',
            ]),
        ),
    })
    .superRefine((value, context) => {
        if (!value.entidade_id) {
            context.addIssue({
                code: 'custom',
                path: ['entidade_id'],
                message: 'Selecione a entidade.',
            });
        }

        if (
            value.responsavel_password !==
            value.responsavel_password_confirmation
        ) {
            context.addIssue({
                code: 'custom',
                path: ['responsavel_password_confirmation'],
                message: 'As senhas não coincidem.',
            });
        }
    });
type Values = z.infer<typeof schema>;

export default function OfficeForm({
    office,
    responsible,
    entidade,
    entidades,
    moduleCatalog,
    modules,
}: Props) {
    const editing = office !== null;
    const {
        control,
        register,
        setValue,
        setError,
        clearErrors,
        handleSubmit,
        formState: { errors },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            entidade_id: entidade?.id.toString() ?? '',
            tipo_gabinete:
                office?.tipo_gabinete ??
                entidade?.tipos_gabinete[0]?.value ??
                'GABINETE_INDEPENDENTE',
            nome: office?.nome ?? '',
            vereador_nome: office?.vereador_nome ?? '',
            numero_eleitoral: office?.numero_eleitoral ?? '',
            municipio: office?.municipio ?? entidade?.city ?? '',
            estado: office?.estado ?? entidade?.state ?? '',
            endereco: office?.endereco ?? '',
            numero: office?.numero ?? '',
            complemento: office?.complemento ?? '',
            bairro: office?.bairro ?? '',
            cep: applyMask(office?.cep, 'cep'),
            timezone:
                office?.timezone ?? entidade?.timezone ?? 'America/Fortaleza',
            telefone: applyMask(office?.telefone, 'phone'),
            email: office?.email ?? '',
            responsavel_nome: responsible?.name ?? '',
            responsavel_email: responsible?.email ?? '',
            responsavel_password: '',
            responsavel_password_confirmation: '',
            modules,
        },
    });
    const selectedModules = useWatch({ control, name: 'modules' });
    const entidadeId = useWatch({ control, name: 'entidade_id' });
    const tipoGabinete = useWatch({ control, name: 'tipo_gabinete' });
    const selectedEntidade = useMemo(
        () =>
            editing
                ? entidade
                : entidades.find((item) => item.id.toString() === entidadeId),
        [editing, entidade, entidadeId, entidades],
    );
    const allowedGabineteTypes = useMemo(
        () => selectedEntidade?.tipos_gabinete ?? [],
        [selectedEntidade],
    );
    const selectedUnit = allowedGabineteTypes.find(
        (item) => item.value === tipoGabinete,
    );
    const leaderLabel =
        selectedUnit?.leader_label ??
        (office?.tipo_gabinete === 'GABINETE_VEREADOR'
            ? 'Vereador'
            : 'Responsável');
    const hasElectoralNumber = [
        'GABINETE_INDEPENDENTE',
        'GABINETE_VEREADOR',
        'GABINETE_PREFEITO',
    ].includes(tipoGabinete);
    const moduleErrors = getOfficeModuleSelectionErrors(
        selectedModules,
        moduleCatalog,
    );
    const [isSaving, setIsSaving] = useState(false);
    const validationMessages = Object.values(errors)
        .map((error) => error?.message)
        .filter((message): message is string => typeof message === 'string');
    const focusErrorSummary = () => {
        window.setTimeout(() => {
            const summary = document.getElementById(
                'office-form-error-summary',
            );
            summary?.focus();
            summary?.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
        }, 0);
    };

    useEffect(() => {
        if (
            editing ||
            allowedGabineteTypes.some((item) => item.value === tipoGabinete)
        ) {
            return;
        }

        const nextType = allowedGabineteTypes[0]?.value;

        if (nextType) {
            setValue('tipo_gabinete', nextType, { shouldValidate: true });
        }
    }, [allowedGabineteTypes, editing, setValue, tipoGabinete]);

    useEffect(() => {
        if (editing || !selectedEntidade) {
            return;
        }

        setValue('municipio', selectedEntidade.city, {
            shouldValidate: true,
        });
        setValue('estado', selectedEntidade.state, {
            shouldValidate: true,
        });
        setValue('timezone', selectedEntidade.timezone, {
            shouldValidate: true,
        });
    }, [editing, selectedEntidade, setValue]);

    const submit = (values: Values) => {
        if (!editing && !values.responsavel_password) {
            setError('responsavel_password', {
                message: 'Defina a senha inicial.',
            });
            focusErrorSummary();

            return;
        }

        if (!editing && moduleErrors.length > 0) {
            setError('modules', { message: moduleErrors[0] });
            focusErrorSummary();

            return;
        }

        clearErrors();
        setIsSaving(true);
        const options = {
            preserveScroll: true,
            onError: (items: Record<string, string>) => {
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                );
                focusErrorSummary();
            },
            onFinish: () => setIsSaving(false),
        };

        if (editing) {
            router.put(`/admin/gabinetes/${office.id}`, values, options);
        } else {
            router.post('/admin/gabinetes', values, options);
        }
    };
    const field = (
        name: keyof Values,
        label: string,
        type = 'text',
        description?: string,
    ) => (
        <div className="space-y-1">
            <FieldLabel htmlFor={name} help={description} helpTitle={label}>
                {label}
            </FieldLabel>
            <Input id={name} type={type} {...register(name)} />
            <FieldError message={errors[name]?.message as string | undefined} />
        </div>
    );
    const moduleCard = !editing ? (
        <Card className="gap-0 py-0">
            <SurfaceHeader help="Defina quais áreas estarão disponíveis neste gabinete. A seleção poderá ser alterada depois sem apagar os dados existentes.">
                <SurfaceTitle>Módulos do gabinete</SurfaceTitle>
            </SurfaceHeader>
            <div className="p-5">
                <OfficeModuleSelector
                    catalog={moduleCatalog}
                    selected={selectedModules}
                    errors={moduleErrors}
                    idPrefix="create-office-module"
                    onToggle={(module, checked) => {
                        const next = toggleOfficeModule(
                            selectedModules,
                            module,
                            checked,
                        );
                        setValue('modules', next, { shouldValidate: true });
                    }}
                />
                <FieldError message={errors.modules?.message} />
            </div>
        </Card>
    ) : null;

    return (
        <>
            <Head title={editing ? 'Editar gabinete' : 'Novo gabinete'} />
            <PageContainer>
                <PageHeader
                    title={editing ? 'Editar gabinete' : 'Novo gabinete'}
                    description={
                        editing
                            ? 'Atualize os dados operacionais e a conta responsável pelo gabinete.'
                            : 'Escolha a entidade e informe apenas os dados próprios deste gabinete.'
                    }
                />
                <form
                    noValidate
                    onSubmit={handleSubmit(submit, focusErrorSummary)}
                    className="space-y-6"
                >
                    {validationMessages.length > 0 && (
                        <div id="office-form-error-summary" tabIndex={-1}>
                            <Alert variant="destructive">
                                <DangerCircleIcon />
                                <AlertTitle>
                                    Revise os dados antes de continuar
                                </AlertTitle>
                                <AlertDescription>
                                    <ul className="list-disc space-y-1 pl-5">
                                        {validationMessages.map(
                                            (message, index) => (
                                                <li key={`${message}-${index}`}>
                                                    {message}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        </div>
                    )}
                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="O gabinete herdará município, UF e fuso horário da entidade selecionada.">
                            <SurfaceTitle>Entidade</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="space-y-3 p-5">
                            {!editing ? (
                                <>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                                        <div className="min-w-0 flex-1 space-y-2">
                                            <AppSelect
                                                id="entidade_id"
                                                value={entidadeId}
                                                disabled={
                                                    entidades.length === 0
                                                }
                                                onValueChange={(value) =>
                                                    setValue(
                                                        'entidade_id',
                                                        value,
                                                        {
                                                            shouldValidate: true,
                                                        },
                                                    )
                                                }
                                                placeholder="Selecione a entidade"
                                                options={entidades.map(
                                                    (item) => ({
                                                        value: item.id.toString(),
                                                        label: `${item.name} · ${item.type_label} · ${item.city}/${item.state}`,
                                                    }),
                                                )}
                                            />
                                            <FieldError
                                                message={
                                                    errors.entidade_id?.message
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="sm:shrink-0"
                                            asChild
                                        >
                                            <Link href="/admin/entidades/nova">
                                                <AddIcon
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Nova entidade
                                            </Link>
                                        </Button>
                                    </div>
                                    {entidades.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Nenhuma entidade disponível.
                                            Cadastre uma entidade antes de
                                            continuar.
                                        </p>
                                    )}
                                </>
                            ) : null}
                            {selectedEntidade && (
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                    <span className="font-medium">
                                        {selectedEntidade.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {selectedEntidade.type_label} ·{' '}
                                        {selectedEntidade.city}/
                                        {selectedEntidade.state} ·{' '}
                                        {selectedEntidade.timezone}
                                    </span>
                                </div>
                            )}
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="O gabinete é o espaço operacional dentro da entidade. Para este cadastro, “Administrativo” é um gabinete do tipo “Setor administrativo”.">
                            <SurfaceTitle>Dados do gabinete</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            {!editing && (
                                <div className="space-y-1 md:col-span-2">
                                    <Label htmlFor="tipo_gabinete">
                                        Tipo do gabinete
                                    </Label>
                                    <AppSelect
                                        id="tipo_gabinete"
                                        value={tipoGabinete}
                                        disabled={
                                            allowedGabineteTypes.length === 0
                                        }
                                        onValueChange={(value) =>
                                            setValue(
                                                'tipo_gabinete',
                                                value as GabineteTypeCode,
                                                { shouldValidate: true },
                                            )
                                        }
                                        placeholder="Selecione o tipo"
                                        options={allowedGabineteTypes.map(
                                            (type) => ({
                                                value: type.value,
                                                label: type.label,
                                            }),
                                        )}
                                    />
                                    <FieldError
                                        message={errors.tipo_gabinete?.message}
                                    />
                                </div>
                            )}
                            {field(
                                'nome',
                                'Nome do gabinete',
                                'text',
                                'Identifica o gabinete dentro da entidade.',
                            )}
                            {field(
                                'vereador_nome',
                                `Nome do ${leaderLabel.toLocaleLowerCase('pt-BR')}`,
                                'text',
                                'Nome institucional da pessoa responsável pelo gabinete.',
                            )}
                            {hasElectoralNumber &&
                                field(
                                    'numero_eleitoral',
                                    'Número na última eleição',
                                )}
                            <div className="space-y-1">
                                <FieldLabel
                                    htmlFor="timezone"
                                    help="O fuso horário é definido pela entidade vinculada ao gabinete."
                                >
                                    Fuso horário
                                </FieldLabel>
                                <Input
                                    id="timezone"
                                    disabled={
                                        !editing ||
                                        selectedEntidade?.type !==
                                            'GABINETE_INDEPENDENTE'
                                    }
                                    {...register('timezone')}
                                />
                                <FieldError
                                    message={errors.timezone?.message}
                                />
                            </div>
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Localização e endereço</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="p-5">
                            <AddressFields
                                control={control}
                                register={register}
                                setValue={setValue}
                                errors={errors}
                                locationDisabled={
                                    !editing ||
                                    selectedEntidade?.type !==
                                        'GABINETE_INDEPENDENTE'
                                }
                            />
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Contato institucional</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            {field('telefone', 'Telefone')}
                            {field('email', 'E-mail institucional', 'email')}
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="Estes dados criam o usuário que entrará no sistema. O nome de acesso pode ser diferente do nome institucional informado no gabinete.">
                            <SurfaceTitle>
                                {editing
                                    ? 'Conta de acesso'
                                    : 'Conta de acesso do responsável'}
                            </SurfaceTitle>
                        </SurfaceHeader>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            {field(
                                'responsavel_nome',
                                'Nome do usuário de acesso',
                            )}
                            {field(
                                'responsavel_email',
                                'E-mail de acesso',
                                'email',
                            )}
                            {field(
                                'responsavel_password',
                                editing
                                    ? 'Nova senha (opcional)'
                                    : 'Senha inicial',
                                'password',
                                'Use 12 ou mais caracteres, com maiúscula, minúscula, número e símbolo.',
                            )}
                            {field(
                                'responsavel_password_confirmation',
                                'Confirmar senha',
                                'password',
                            )}
                        </div>
                    </Card>
                    {moduleCard}
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
                                ? 'Salvando...'
                                : editing
                                  ? 'Salvar alterações'
                                  : 'Criar gabinete'}
                        </Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

OfficeForm.layout = (page: { office: Office | null }) => ({
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        {
            title: page.office ? 'Editar gabinete' : 'Novo gabinete',
            href: '#',
        },
    ],
});
