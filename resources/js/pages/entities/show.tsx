import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import { HubManagedHint } from '@/components/common/hub-managed-hint';
import { HubStructureLink } from '@/components/common/hub-structure-link';
import { AttachmentField } from '@/components/forms/attachment-field';
import { ColorPicker } from '@/components/forms/color-picker';
import { FieldError } from '@/components/forms/field-error';
import {
    AddIcon,
    AltArrowLeftIcon,
    AltArrowRightIcon,
    Buildings2Icon,
    CloseIcon,
    DisketteIcon,
    MagnifierIcon,
    RestartIcon,
    ShieldCheckIcon,
    ShieldCrossIcon,
    ShieldIcon,
    ShieldWarningIcon,
    TransferHorizontalIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Surface,
    SurfaceHeader,
    SurfaceTitle,
    SurfaceDescription,
} from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { accessRoleLabel } from '@/lib/access-roles';
import { checkRequiredFields } from '@/lib/required-fields';

const SYSTEM_PRIMARY_COLOR = '#ca3500';
const SYSTEM_SECONDARY_COLOR = '#333333';
const GABINETES_PAGE_SIZE = 6;
const normalizeSearch = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('pt-BR');

const licenseStatusConfig: Record<
    string,
    { icon: typeof ShieldCheckIcon; className: string; label: string }
> = {
    ATIVA: {
        icon: ShieldCheckIcon,
        className: 'text-emerald-600 dark:text-emerald-400',
        label: 'Licença ativa',
    },
    AVALIACAO: {
        icon: ShieldIcon,
        className: 'text-amber-600 dark:text-amber-400',
        label: 'Licença em avaliação',
    },
    EXPIRADA: {
        icon: ShieldWarningIcon,
        className: 'text-destructive',
        label: 'Licença expirada',
    },
    SUSPENSA: {
        icon: ShieldCrossIcon,
        className: 'text-destructive',
        label: 'Licença suspensa',
    },
};

type Entidade = {
    id: number;
    name: string;
    slug: string;
    type_label: string;
    status: string;
    city: string;
    state: string;
    timezone: string;
    logo_path: string | null;
    logo_url: string | null;
    primary_color: string | null;
    secondary_color: string | null;
    simplified_interface: boolean;
    hub_linked: boolean;
};

type SharedNeighborhood = {
    id: number;
    name: string;
    city: string;
    state: string;
    active: boolean;
    gabinetes_count: number;
};

type Gabinete = {
    id: number;
    name: string;
    slug: string;
    type_label: string;
    active: boolean;
    can_access: boolean;
    can_invite_members: boolean;
};

type ModuleDefinition = {
    code: string;
    name: string;
    scope: string;
};

type Member = {
    id: number;
    name: string | null;
    email: string | null;
    role: string;
    active: boolean;
};

type Invitation = {
    id: string;
    email: string;
    status: string;
    expires_at: string;
};

export default function EntidadeShow({
    entidade,
    gabinetes,
    members,
    invitations,
    sharedNeighborhoods,
    moduleCatalog,
    activeModules,
    license,
    canManage,
    canManageModules,
    canCreateGabinete,
}: {
    entidade: Entidade;
    gabinetes: Gabinete[];
    members: Member[];
    invitations: Invitation[];
    sharedNeighborhoods: SharedNeighborhood[];
    moduleCatalog: ModuleDefinition[];
    activeModules: string[];
    license: { status: string; ends_at: string | null } | null;
    canManage: boolean;
    canManageModules: boolean;
    canCreateGabinete: boolean;
}) {
    const settings = useForm({
        name: entidade.name,
        timezone: entidade.timezone,
        primary_color: entidade.primary_color ?? '',
        secondary_color: entidade.secondary_color ?? '',
        simplified_interface: entidade.simplified_interface,
        logo: null as File | null,
        remove_logo: false,
    });
    const logoFiles = settings.data.logo ? [settings.data.logo] : [];
    const hasCurrentLogo =
        Boolean(entidade.logo_url) && !settings.data.remove_logo;
    const licenseStatus = license
        ? (licenseStatusConfig[license.status] ?? {
              icon: ShieldIcon,
              className: 'text-muted-foreground',
              label: license.status,
          })
        : {
              icon: ShieldIcon,
              className: 'text-muted-foreground',
              label: 'Licença não configurada',
          };
    const [gabinetesQuery, setGabinetesQuery] = useState('');
    const [gabinetesPage, setGabinetesPage] = useState(1);
    const filteredGabinetes = useMemo(() => {
        const query = normalizeSearch(gabinetesQuery.trim());

        if (query === '') {
            return gabinetes;
        }

        return gabinetes.filter((gabinete) =>
            normalizeSearch(`${gabinete.name} ${gabinete.type_label}`).includes(
                query,
            ),
        );
    }, [gabinetes, gabinetesQuery]);
    const gabinetesPageCount = Math.max(
        1,
        Math.ceil(filteredGabinetes.length / GABINETES_PAGE_SIZE),
    );
    const currentGabinetesPage = Math.min(gabinetesPage, gabinetesPageCount);
    const visibleGabinetes = filteredGabinetes.slice(
        (currentGabinetesPage - 1) * GABINETES_PAGE_SIZE,
        currentGabinetesPage * GABINETES_PAGE_SIZE,
    );

    const neighborhood = useForm({ name: '', active: true });

    const saveSettings = (event: FormEvent) => {
        event.preventDefault();

        if (
            !checkRequiredFields(settings.data, settings, {
                name: 'Informe o nome da organização.',
            })
        ) {
            return;
        }

        settings.post(`/entidades/${entidade.slug}/identidade`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => settings.setData('logo', null),
        });
    };

    const addSharedNeighborhood = (event: FormEvent) => {
        event.preventDefault();

        if (
            !checkRequiredFields(neighborhood.data, neighborhood, {
                name: 'Informe o nome do bairro.',
            })
        ) {
            return;
        }

        neighborhood.post(`/entidades/${entidade.slug}/bairros`, {
            preserveScroll: true,
            onSuccess: () => neighborhood.reset('name'),
        });
    };

    const toggleSharedNeighborhood = (item: SharedNeighborhood) => {
        router.patch(
            `/entidades/${entidade.slug}/bairros/${item.id}`,
            { name: item.name, active: !item.active },
            { preserveScroll: true },
        );
    };

    const toggleModule = (code: string, checked: boolean) => {
        const modules = checked
            ? [...new Set([...activeModules, code])]
            : activeModules.filter((module) => module !== code);
        router.patch(
            `/entidades/${entidade.slug}/modulos`,
            { modules },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={entidade.name} />
            <PageContainer>
                <PageHeader
                    title={
                        <span className="inline-flex items-center gap-2">
                            {entidade.name}
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span
                                        tabIndex={0}
                                        aria-label={licenseStatus.label}
                                        className="inline-flex shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <licenseStatus.icon
                                            className={`size-5 ${licenseStatus.className}`}
                                            aria-hidden="true"
                                        />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {licenseStatus.label}
                                </TooltipContent>
                            </Tooltip>
                        </span>
                    }
                    description={`${entidade.type_label} · ${entidade.city}/${entidade.state}`}
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader
                        actions={
                            <div className="flex shrink-0 items-center gap-2">
                                <Badge variant="outline">
                                    {gabinetesQuery === ''
                                        ? gabinetes.length
                                        : `${filteredGabinetes.length}/${gabinetes.length}`}
                                </Badge>
                                {canManage && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/entidades/${entidade.slug}/transferencias`}
                                        >
                                            <TransferHorizontalIcon className="size-4" />
                                            Transferências
                                        </Link>
                                    </Button>
                                )}
                                {canCreateGabinete && (
                                    <HubStructureLink
                                        label="Novo gabinete no Hub"
                                        size="sm"
                                    />
                                )}
                            </div>
                        }
                    >
                        <SurfaceTitle>Gabinetes</SurfaceTitle>
                        <SurfaceDescription>
                            Selecione onde deseja trabalhar
                        </SurfaceDescription>
                    </SurfaceHeader>
                    <div className="flex items-center gap-2 border-b p-4">
                        <div className="relative min-w-52 flex-1">
                            <MagnifierIcon
                                className="absolute top-2.5 left-3 size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                value={gabinetesQuery}
                                onChange={(event) => {
                                    setGabinetesQuery(event.target.value);
                                    setGabinetesPage(1);
                                }}
                                className="pl-9"
                                placeholder="Buscar gabinete por nome ou tipo"
                                aria-label="Buscar gabinetes desta entidade"
                            />
                        </div>
                        {gabinetesQuery !== '' && (
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => {
                                    setGabinetesQuery('');
                                    setGabinetesPage(1);
                                }}
                                aria-label="Limpar busca de gabinetes"
                            >
                                <CloseIcon aria-hidden="true" />
                            </Button>
                        )}
                    </div>
                    {visibleGabinetes.length === 0 ? (
                        <p className="p-6 text-center text-sm text-muted-foreground">
                            Nenhum gabinete corresponde à busca.
                        </p>
                    ) : (
                        <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                            {visibleGabinetes.map((gabinete) => (
                                <div
                                    key={gabinete.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-start gap-2">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-sm bg-muted">
                                            <Buildings2Icon
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <h2
                                                className="truncate text-sm font-semibold"
                                                title={gabinete.name}
                                            >
                                                {gabinete.name}
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                {gabinete.type_label}
                                            </p>
                                        </div>
                                    </div>
                                    {gabinete.can_access && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="mt-3 w-full"
                                            asChild
                                        >
                                            <Link
                                                href={`/entidades/${entidade.slug}/gabinetes/${gabinete.slug}/dashboard`}
                                            >
                                                Entrar no gabinete
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                    {gabinetesPageCount > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t p-4">
                            <p className="text-xs text-muted-foreground">
                                Página {currentGabinetesPage} de{' '}
                                {gabinetesPageCount}
                            </p>
                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="outline"
                                    disabled={currentGabinetesPage === 1}
                                    onClick={() =>
                                        setGabinetesPage((page) => page - 1)
                                    }
                                    aria-label="Página anterior de gabinetes"
                                >
                                    <AltArrowLeftIcon className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="outline"
                                    disabled={
                                        currentGabinetesPage ===
                                        gabinetesPageCount
                                    }
                                    onClick={() =>
                                        setGabinetesPage((page) => page + 1)
                                    }
                                    aria-label="Próxima página de gabinetes"
                                >
                                    <AltArrowRightIcon className="size-4" />
                                </Button>
                            </div>
                        </div>
                    )}
                </Surface>

                {canManage && (
                    <>
                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle>Integrantes</SurfaceTitle>
                            </SurfaceHeader>
                            <div className="border-b p-4">
                                <HubManagedHint text="Pessoas e vínculos são geridos no Govnex Hub. Para convidar ou vincular alguém a esta entidade, acesse o Hub." />
                            </div>
                            <div className="divide-y">
                                {members.map((member) => (
                                    <div key={member.id} className="p-4">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-medium">
                                                {member.name}
                                            </p>
                                            <Badge variant="outline">
                                                {accessRoleLabel(member.role)}
                                            </Badge>
                                        </div>
                                        <p className="truncate text-sm text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            {invitations.length > 0 && (
                                <div className="border-t p-4">
                                    <h3 className="text-sm font-medium">
                                        Convites recentes
                                    </h3>
                                    <ul className="mt-2 space-y-2 text-sm">
                                        {invitations.map((item) => (
                                            <li
                                                key={item.id}
                                                className="flex flex-wrap justify-between gap-2"
                                            >
                                                <span>{item.email}</span>
                                                <Badge variant="outline">
                                                    {item.status}
                                                </Badge>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </Surface>

                        {canManageModules && (
                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader help="A licença precisa contratar o módulo antes de ele poder ser ativado.">
                                    <SurfaceTitle>
                                        Módulos da entidade
                                    </SurfaceTitle>
                                </SurfaceHeader>
                                <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {moduleCatalog.map((module) => (
                                        <div
                                            key={module.code}
                                            className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3"
                                        >
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium">
                                                    {module.name}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {module.scope}
                                                </span>
                                            </span>
                                            <Switch
                                                checked={activeModules.includes(
                                                    module.code,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleModule(
                                                        module.code,
                                                        checked,
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            </Surface>
                        )}

                        <div className="grid gap-6 lg:grid-cols-2">
                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader>
                                    <SurfaceTitle>
                                        Identidade visual
                                    </SurfaceTitle>
                                </SurfaceHeader>
                                <form
                                    noValidate
                                    className="space-y-4 p-4"
                                    onSubmit={saveSettings}
                                >
                                    <AttachmentField
                                        title="Logo institucional"
                                        helperText="PNG, JPG ou WebP com até 2 MB."
                                        files={logoFiles}
                                        onFilesChange={(files) => {
                                            settings.setData(
                                                'logo',
                                                files[0] ?? null,
                                            );
                                            settings.setData(
                                                'remove_logo',
                                                false,
                                            );
                                        }}
                                        current={
                                            hasCurrentLogo
                                                ? {
                                                      name: `Logo de ${entidade.name}`,
                                                      url: entidade.logo_url,
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
                                        selectLabel={
                                            hasCurrentLogo ||
                                            logoFiles.length > 0
                                                ? 'Trocar logo'
                                                : 'Enviar logo'
                                        }
                                    />
                                    <div className="space-y-1">
                                        <Label htmlFor="entidade-name">
                                            Nome
                                        </Label>
                                        <Input
                                            id="entidade-name"
                                            aria-required="true"
                                            disabled={entidade.hub_linked}
                                            value={settings.data.name}
                                            onChange={(event) =>
                                                settings.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {entidade.hub_linked && (
                                            <FieldDescription>
                                                Definido no Govnex Hub — altere
                                                lá.
                                            </FieldDescription>
                                        )}
                                        <FieldError
                                            message={settings.errors.name}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-1">
                                                <Label htmlFor="primary-color">
                                                    Cor principal
                                                </Label>
                                                <ColorPicker
                                                    id="primary-color"
                                                    aria-label="Cor principal"
                                                    value={
                                                        settings.data
                                                            .primary_color ||
                                                        SYSTEM_PRIMARY_COLOR
                                                    }
                                                    onChange={(hex) =>
                                                        settings.setData(
                                                            'primary_color',
                                                            hex,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label htmlFor="secondary-color">
                                                    Cor secundária
                                                </Label>
                                                <ColorPicker
                                                    id="secondary-color"
                                                    aria-label="Cor secundária"
                                                    value={
                                                        settings.data
                                                            .secondary_color ||
                                                        SYSTEM_SECONDARY_COLOR
                                                    }
                                                    onChange={(hex) =>
                                                        settings.setData(
                                                            'secondary_color',
                                                            hex,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3">
                                        <span className="min-w-0">
                                            <span className="block text-sm font-medium">
                                                Interface simplificada
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                Reduz elementos institucionais
                                                quando a entidade possui um
                                                único gabinete.
                                            </span>
                                        </span>
                                        <Switch
                                            checked={
                                                settings.data
                                                    .simplified_interface
                                            }
                                            onCheckedChange={(checked) =>
                                                settings.setData(
                                                    'simplified_interface',
                                                    checked,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            disabled={settings.processing}
                                        >
                                            <DisketteIcon className="size-4" />
                                            Salvar
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                settings.setData('logo', null);
                                                settings.setData(
                                                    'remove_logo',
                                                    true,
                                                );
                                                settings.setData(
                                                    'primary_color',
                                                    '',
                                                );
                                                settings.setData(
                                                    'secondary_color',
                                                    '',
                                                );
                                            }}
                                        >
                                            <RestartIcon className="size-4" />
                                            Restaurar
                                        </Button>
                                    </div>
                                </form>
                            </Surface>

                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader help="Catálogo público compartilhado pelos gabinetes. Cidadãos e rotinas permanecem privados em cada gabinete.">
                                    <SurfaceTitle>
                                        Referências territoriais
                                    </SurfaceTitle>
                                </SurfaceHeader>
                                <div className="p-4">
                                    <form
                                        noValidate
                                        className="flex flex-col gap-3 sm:flex-row"
                                        onSubmit={addSharedNeighborhood}
                                    >
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <Label
                                                htmlFor="shared-neighborhood-name"
                                                className="sr-only"
                                            >
                                                Nome do bairro
                                            </Label>
                                            <Input
                                                id="shared-neighborhood-name"
                                                aria-required="true"
                                                value={neighborhood.data.name}
                                                placeholder="Nome do bairro"
                                                onChange={(event) =>
                                                    neighborhood.setData(
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <FieldError
                                                message={
                                                    neighborhood.errors.name
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={neighborhood.processing}
                                        >
                                            <AddIcon className="size-4" />
                                            Adicionar
                                        </Button>
                                    </form>

                                    <div className="mt-4 divide-y rounded-md border">
                                        {sharedNeighborhoods.length === 0 ? (
                                            <p className="p-4 text-sm text-muted-foreground">
                                                Nenhuma referência cadastrada.
                                            </p>
                                        ) : (
                                            sharedNeighborhoods.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="flex flex-wrap items-center gap-3 p-3"
                                                >
                                                    <ActivityMark
                                                        active={item.active}
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <span className="block text-sm font-medium">
                                                            {item.name}
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {item.city}/
                                                            {item.state} ·{' '}
                                                            {
                                                                item.gabinetes_count
                                                            }{' '}
                                                            gabinete(s)
                                                        </span>
                                                    </div>
                                                    <ActivityToggleButton
                                                        active={item.active}
                                                        name={item.name}
                                                        onClick={() =>
                                                            toggleSharedNeighborhood(
                                                                item,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            ))
                                        )}
                                    </div>
                                </div>
                            </Surface>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

EntidadeShow.layout = {
    breadcrumbs: [
        { title: 'Entidades', href: '/entidades' },
        { title: 'Entidade e gabinetes', href: '#' },
    ],
};
