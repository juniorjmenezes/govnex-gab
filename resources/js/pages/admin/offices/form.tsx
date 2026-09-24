import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { AddressFields } from '@/components/forms/address-fields';
import { FieldError } from '@/components/forms/field-error';
import { FieldLabel } from '@/components/forms/field-label';
import { DangerCircleIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { applyMask } from '@/lib/masks';

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
type EntidadeSummary = {
    id: number;
    name: string;
    type: EntidadeTypeCode;
    type_label: string;
    city: string;
    state: string;
    timezone: string;
    gabinetes_count: number;
    tipos_gabinete: GabineteTypeOption[];
} | null;
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
    hub_unidade_id: string | null;
};
type Responsible = { name: string; email: string } | null;

type Props = {
    office: Office;
    responsible: Responsible;
    entidade: EntidadeSummary;
};

const schema = z.object({
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
});
type Values = z.infer<typeof schema>;

/**
 * Edição de um gabinete pela administração. Criar entidade ou gabinete não
 * acontece mais no GAB (a estrutura nasce no Govnex Hub), e a conta do
 * responsável também vem de lá, pelos vínculos — aqui ela só é exibida.
 */
export default function OfficeForm({ office, responsible, entidade }: Props) {
    const nameManagedByHub = office.hub_unidade_id != null;
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
            nome: office.nome,
            vereador_nome: office.vereador_nome ?? '',
            numero_eleitoral: office.numero_eleitoral ?? '',
            municipio: office.municipio,
            estado: office.estado,
            endereco: office.endereco ?? '',
            numero: office.numero ?? '',
            complemento: office.complemento ?? '',
            bairro: office.bairro ?? '',
            cep: applyMask(office.cep, 'cep'),
            timezone:
                office.timezone ?? entidade?.timezone ?? 'America/Fortaleza',
            telefone: applyMask(office.telefone, 'phone'),
            email: office.email ?? '',
        },
    });
    const leaderLabel =
        entidade?.tipos_gabinete.find(
            (item) => item.value === office.tipo_gabinete,
        )?.leader_label ??
        (office.tipo_gabinete === 'GABINETE_VEREADOR'
            ? 'Vereador'
            : 'Responsável');
    const hasElectoralNumber = [
        'GABINETE_INDEPENDENTE',
        'GABINETE_VEREADOR',
        'GABINETE_PREFEITO',
    ].includes(office.tipo_gabinete);
    const locationDisabled = entidade?.type !== 'GABINETE_INDEPENDENTE';
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

    const submit = (values: Values) => {
        clearErrors();
        setIsSaving(true);
        // Campo desabilitado quando o nome vem do Govnex Hub: reenvia o atual.
        router.put(
            `/admin/gabinetes/${office.id}`,
            nameManagedByHub ? { ...values, nome: office.nome } : values,
            {
                preserveScroll: true,
                onError: (items: Record<string, string>) => {
                    Object.entries(items).forEach(([key, message]) =>
                        setError(key as keyof Values, { message }),
                    );
                    focusErrorSummary();
                },
                onFinish: () => setIsSaving(false),
            },
        );
    };
    const field = (
        name: keyof Values,
        label: string,
        type = 'text',
        description?: string,
        hint?: string,
    ) => (
        <div className="space-y-1">
            <FieldLabel htmlFor={name} help={description} helpTitle={label}>
                {label}
            </FieldLabel>
            <Input
                id={name}
                type={type}
                disabled={hint !== undefined}
                {...register(name)}
            />
            {hint !== undefined && <FieldDescription>{hint}</FieldDescription>}
            <FieldError message={errors[name]?.message as string | undefined} />
        </div>
    );

    return (
        <>
            <Head title="Editar gabinete" />
            <PageContainer>
                <PageHeader
                    title="Editar gabinete"
                    description="Atualize os dados operacionais do gabinete. Estrutura, contas e vínculos são geridos no Govnex Hub."
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
                        <SurfaceHeader help="O gabinete herda município, UF e fuso horário da entidade.">
                            <SurfaceTitle>Entidade</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="space-y-3 p-5">
                            {entidade && (
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                    <span className="font-medium">
                                        {entidade.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {entidade.type_label} · {entidade.city}/
                                        {entidade.state} · {entidade.timezone}
                                    </span>
                                </div>
                            )}
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="O gabinete é o espaço operacional dentro da entidade.">
                            <SurfaceTitle>Dados do gabinete</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            {field(
                                'nome',
                                'Nome do gabinete',
                                'text',
                                'Identifica o gabinete dentro da entidade.',
                                nameManagedByHub
                                    ? 'Definido no Govnex Hub — altere lá.'
                                    : undefined,
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
                                    disabled={locationDisabled}
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
                                locationDisabled={locationDisabled}
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
                        <SurfaceHeader help="Contas de acesso e vínculos são criados e alterados no Govnex Hub. Aqui aparece o administrador ativo mais antigo do gabinete.">
                            <SurfaceTitle>Conta de acesso</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="p-5 text-sm">
                            {responsible ? (
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span className="font-medium">
                                        {responsible.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {responsible.email}
                                    </span>
                                </div>
                            ) : (
                                <p className="text-muted-foreground">
                                    Nenhum administrador vinculado. Conceda o
                                    vínculo no Govnex Hub.
                                </p>
                            )}
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
                            {isSaving ? 'Salvando...' : 'Salvar alterações'}
                        </Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

OfficeForm.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        { title: 'Editar gabinete', href: '#' },
    ],
};
