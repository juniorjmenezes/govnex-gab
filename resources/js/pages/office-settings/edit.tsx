import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import { AddressFields } from '@/components/forms/address-fields';
import { AttachmentField } from '@/components/forms/attachment-field';
import { ColorPicker } from '@/components/forms/color-picker';
import { FieldError } from '@/components/forms/field-error';
import {
    DangerTriangleIcon,
    GalleryIcon,
    PaletteIcon,
    RestartIcon,
    ShieldCheckIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MaskedInput } from '@/components/ui/masked-input';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { applyMask } from '@/lib/masks';
import type { MaskType } from '@/lib/masks';
import type { Auth } from '@/types';
type Office = {
    id: number;
    nome: string;
    vereador_nome: string;
    numero_eleitoral: string | null;
    municipio: string;
    estado: string;
    timezone: string;
    telefone: string | null;
    email: string | null;
    endereco: string | null;
    numero: string | null;
    complemento: string | null;
    bairro: string | null;
    cep: string | null;
    logo_path: string | null;
    cor_principal: string | null;
    formato_protocolo: string | null;
    cabecalho_relatorios: string | null;
};
const schema = z.object({
    nome: z.string().min(2, 'Informe o nome do gabinete.'),
    vereador_nome: z.string().min(2, 'Informe o parlamentar.'),
    partido: z.string(),
    legislatura: z.string(),
    municipio: z.string().min(2),
    estado: z.string().length(2),
    numero: z.string(),
    complemento: z.string(),
    cep: z.string(),
    timezone: z.string().min(3, 'Informe o timezone.'),
    telefone: z.string(),
    email: z.union([z.literal(''), z.email('E-mail inválido.')]),
    endereco: z.string(),
    bairro: z.string(),
    cor_principal: z
        .string()
        .regex(/^#[0-9A-Fa-f]{6}$/, 'Use uma cor hexadecimal.'),
    formato_protocolo: z
        .string()
        .refine(
            (v) => v.includes('{ANO}') && v.includes('{SEQUENCIAL}'),
            'Inclua {ANO} e {SEQUENCIAL}.',
        ),
    cabecalho_relatorios: z.string(),
    logo: z.array(z.instanceof(File)).optional(),
    usar_cor_padrao: z.boolean(),
    remover_logo: z.boolean(),
});
type Values = z.infer<typeof schema>;
type ElectoralCandidate = {
    matched: boolean;
    name: string | null;
    party: string | null;
};
const value = (item: string | null) => item ?? '';
const SYSTEM_PRIMARY_COLOR = '#C44F00';
export default function OfficeSettings({
    office,
    settings,
    canUpdate,
    electoralCandidate,
}: {
    office: Office;
    settings: { partido: string | null; legislatura: string | null };
    canUpdate: boolean;
    electoralCandidate: ElectoralCandidate;
}) {
    const tenantUrl = useTenantUrl();
    const { auth } = usePage<{ auth: Auth }>().props;
    const inheritedPrimaryColor =
        auth.context.entidade?.primary_color ?? SYSTEM_PRIMARY_COLOR;
    const {
        control,
        register,
        handleSubmit,
        setError,
        setValue,
        resetField,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            nome: office.nome,
            vereador_nome: office.vereador_nome,
            partido: value(settings.partido),
            legislatura: value(settings.legislatura),
            municipio: office.municipio,
            estado: office.estado,
            numero: value(office.numero),
            complemento: value(office.complemento),
            cep: applyMask(office.cep, 'cep'),
            timezone: office.timezone ?? 'America/Sao_Paulo',
            telefone: applyMask(office.telefone, 'phone'),
            email: value(office.email),
            endereco: value(office.endereco),
            bairro: value(office.bairro),
            cor_principal: office.cor_principal ?? inheritedPrimaryColor,
            formato_protocolo: office.formato_protocolo ?? '{ANO}-{SEQUENCIAL}',
            cabecalho_relatorios: value(office.cabecalho_relatorios),
            usar_cor_padrao: office.cor_principal === null,
            remover_logo: false,
        },
    });
    const useDefaultColor = useWatch({ control, name: 'usar_cor_padrao' });
    const removeLogo = useWatch({ control, name: 'remover_logo' });
    const logoFiles = useWatch({ control, name: 'logo' }) ?? [];
    const hasCurrentLogo = Boolean(office.logo_path) && !removeLogo;

    const submit = (values: Values) => {
        const data = new FormData();
        Object.entries(values).forEach(([key, item]) => {
            if (key !== 'logo') {
                data.append(
                    key,
                    typeof item === 'boolean'
                        ? item
                            ? '1'
                            : '0'
                        : String(item),
                );
            }
        });
        const logo = values.logo?.[0];

        if (logo) {
            data.append('logo', logo);
        }

        data.append('_method', 'put');
        router.post(tenantUrl('/configuracoes/gabinete'), data, {
            forceFormData: true,
            onError: (items) =>
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });
    };
    const input = (
        name: keyof Omit<Values, 'logo' | 'cabecalho_relatorios'>,
        label: string,
        type = 'text',
        mask?: MaskType,
    ) => (
        <div className="space-y-1">
            <Label className="grid items-start gap-1">
                <span>{label}</span>
                {mask ? (
                    <MaskedInput
                        mask={mask}
                        disabled={!canUpdate}
                        {...register(name)}
                    />
                ) : (
                    <Input
                        type={type}
                        disabled={!canUpdate}
                        {...register(name)}
                    />
                )}
            </Label>
            <FieldError message={errors[name]?.message} />
        </div>
    );

    return (
        <>
            <Head title="Configurações do gabinete" />
            <PageContainer>
                <PageHeader
                    title="Configurações do gabinete"
                    description="Identidade institucional, contato e padrões usados em protocolos e relatórios."
                />
                {!canUpdate && (
                    <p className="rounded-lg border bg-muted p-4 text-sm text-muted-foreground">
                        Você pode consultar estas informações. Somente o
                        vereador ou vereadora pode alterá-las.
                    </p>
                )}
                <form onSubmit={handleSubmit(submit)} className="space-y-6">
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Identificação</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="p-5">
                            <div className="grid gap-5 md:grid-cols-2">
                                {input('nome', 'Nome do gabinete')}
                                {input('vereador_nome', 'Vereador(a)')}
                                {input('partido', 'Partido')}
                                <div className="space-y-1">
                                    <Label className="grid items-start gap-1">
                                        <span>Número eleitoral</span>
                                        <Input
                                            value={
                                                office.numero_eleitoral ??
                                                'Não cadastrado'
                                            }
                                            disabled
                                            readOnly
                                        />
                                    </Label>
                                </div>
                                {input('legislatura', 'Legislatura')}
                                {input('timezone', 'Fuso horário')}
                            </div>
                            <div className="mt-5 space-y-2">
                                <div
                                    className={`flex items-start gap-2 rounded-lg border p-3 text-xs ${
                                        electoralCandidate.matched
                                            ? 'border-emerald-600/30 bg-emerald-600/10 text-emerald-700 dark:text-emerald-400'
                                            : 'border-amber-600/30 bg-amber-600/10 text-amber-700 dark:text-amber-400'
                                    }`}
                                >
                                    {electoralCandidate.matched ? (
                                        <ShieldCheckIcon
                                            className="mt-0.5 size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <DangerTriangleIcon
                                            className="mt-0.5 size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <p>
                                        {electoralCandidate.matched ? (
                                            <>
                                                Vinculado à candidatura de{' '}
                                                <strong>
                                                    {electoralCandidate.name}
                                                </strong>
                                                {electoralCandidate.party
                                                    ? ` (${electoralCandidate.party})`
                                                    : ''}{' '}
                                                no TSE. O Mapa de eleitores usa
                                                essa votação.
                                            </>
                                        ) : office.numero_eleitoral ? (
                                            <>
                                                Número cadastrado, mas nenhuma
                                                candidatura sincronizada do TSE
                                                corresponde a ele para
                                                Vereador(a) neste município.
                                                Fale com a administração da
                                                plataforma para revisar o número
                                                ou aguardar a sincronização das
                                                candidaturas municipais.
                                            </>
                                        ) : (
                                            <>
                                                O número eleitoral ainda não foi
                                                cadastrado pela administração da
                                                plataforma. Ele é necessário
                                                para o Mapa de eleitores
                                                funcionar — peça para a
                                                administração configurá-lo em
                                                Gabinetes.
                                            </>
                                        )}
                                    </p>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Definido pela administração da plataforma a
                                    partir do cadastro oficial do TSE.
                                </p>
                            </div>
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Endereço</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="p-5">
                            <AddressFields
                                control={control}
                                register={register}
                                setValue={setValue}
                                errors={errors}
                                disabled={!canUpdate}
                            />
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Contato</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            {input('telefone', 'Telefone', 'text', 'phone')}
                            {input('email', 'E-mail', 'email')}
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="Personalize a marca do gabinete ou restaure o padrão do sistema.">
                            <SurfaceTitle>Identidade visual</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="grid gap-4 p-5 lg:grid-cols-2">
                            <section className="rounded-xl border bg-muted/20 p-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-sm bg-primary/10 text-primary">
                                            <PaletteIcon
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div>
                                            <h3 className="text-sm font-semibold">
                                                Cor institucional
                                            </h3>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                Ações, destaques, gráficos e
                                                relatórios.
                                            </p>
                                        </div>
                                    </div>
                                    <Switch
                                        checked={!useDefaultColor}
                                        onCheckedChange={(checked) => {
                                            setValue(
                                                'usar_cor_padrao',
                                                !checked,
                                            );

                                            if (!checked) {
                                                setValue(
                                                    'cor_principal',
                                                    inheritedPrimaryColor,
                                                );
                                            }
                                        }}
                                        disabled={!canUpdate}
                                        aria-label="Usar cor personalizada"
                                    />
                                </div>

                                <div className="mt-4 rounded-lg border bg-background p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="flex items-center gap-3">
                                            <span
                                                className="size-10 shrink-0 rounded-lg border shadow-sm"
                                                style={{
                                                    backgroundColor:
                                                        useDefaultColor
                                                            ? inheritedPrimaryColor
                                                            : undefined,
                                                }}
                                                aria-hidden="true"
                                            />
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {useDefaultColor
                                                        ? auth.context.entidade
                                                              ?.primary_color
                                                            ? 'Cor da entidade'
                                                            : 'Cor do sistema'
                                                        : 'Cor personalizada'}
                                                </p>
                                                <p className="text-xs text-muted-foreground tabular-nums">
                                                    {useDefaultColor
                                                        ? inheritedPrimaryColor
                                                        : 'Definida pelo gabinete'}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge
                                            variant={
                                                useDefaultColor
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {useDefaultColor
                                                ? 'Padrão'
                                                : 'Personalizada'}
                                        </Badge>
                                    </div>

                                    {!useDefaultColor && (
                                        <Controller
                                            control={control}
                                            name="cor_principal"
                                            render={({ field }) => (
                                                <div className="mt-4 space-y-1">
                                                    <Label htmlFor="office-primary-color">
                                                        Cor personalizada
                                                    </Label>
                                                    <ColorPicker
                                                        id="office-primary-color"
                                                        aria-label="Selecionar cor principal"
                                                        value={field.value}
                                                        onChange={
                                                            field.onChange
                                                        }
                                                        disabled={!canUpdate}
                                                    />
                                                </div>
                                            )}
                                        />
                                    )}
                                </div>
                                <FieldError
                                    message={errors.cor_principal?.message}
                                />
                            </section>

                            <section className="rounded-xl border bg-muted/20 p-4">
                                <div className="flex items-start gap-3">
                                    <span className="grid size-9 shrink-0 place-items-center rounded-sm bg-primary/10 text-primary">
                                        <GalleryIcon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div>
                                        <h3 className="text-sm font-semibold">
                                            Logo do gabinete
                                        </h3>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            PNG, JPG ou WebP, com até 2 MB.
                                        </p>
                                    </div>
                                </div>

                                <AttachmentField
                                    className="mt-4"
                                    files={logoFiles}
                                    onFilesChange={(files) => {
                                        setValue('logo', files);
                                        setValue('remover_logo', false);
                                    }}
                                    current={
                                        hasCurrentLogo
                                            ? {
                                                  name: 'Logo atual',
                                                  url: `/storage/${office.logo_path}`,
                                                  imagem: true,
                                              }
                                            : null
                                    }
                                    maxFiles={1}
                                    maxSizeMb={2}
                                    accept="image/png,image/jpeg,image/webp"
                                    allowedExtensions={[
                                        'png',
                                        'jpg',
                                        'jpeg',
                                        'webp',
                                    ]}
                                    disabled={!canUpdate}
                                    selectLabel={
                                        hasCurrentLogo || logoFiles.length > 0
                                            ? 'Trocar logo'
                                            : 'Enviar logo'
                                    }
                                    error={errors.logo?.message}
                                />
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {canUpdate && hasCurrentLogo && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                resetField('logo');
                                                setValue('remover_logo', true);
                                            }}
                                        >
                                            <RestartIcon />
                                            Usar padrão
                                        </Button>
                                    )}
                                    {canUpdate &&
                                        removeLogo &&
                                        office.logo_path && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setValue(
                                                        'remover_logo',
                                                        false,
                                                    )
                                                }
                                            >
                                                Desfazer
                                            </Button>
                                        )}
                                </div>
                            </section>
                        </div>
                    </Card>
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Documentos</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="space-y-5 p-5">
                            {input('formato_protocolo', 'Formato do protocolo')}
                            <div className="space-y-1">
                                <Label htmlFor="report-header">
                                    Cabeçalho dos relatórios
                                </Label>
                                <Textarea
                                    id="report-header"
                                    rows={5}
                                    disabled={!canUpdate}
                                    {...register('cabecalho_relatorios')}
                                />
                                <FieldError
                                    message={
                                        errors.cabecalho_relatorios?.message
                                    }
                                />
                            </div>
                        </div>
                    </Card>
                    {canUpdate && (
                        <div className="flex justify-end">
                            <Button disabled={isSubmitting}>
                                Salvar configurações
                            </Button>
                        </div>
                    )}
                </form>
            </PageContainer>
        </>
    );
}

OfficeSettings.layout = {
    breadcrumbs: [{ title: 'Configurações', href: '/configuracoes/gabinete' }],
};
