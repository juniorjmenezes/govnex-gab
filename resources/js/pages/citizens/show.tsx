import { Head, Link } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import {
    CalendarMarkIcon,
    ChatRoundIcon,
    DangerTriangleIcon,
    HandShakeIcon,
    LetterIcon,
    MapPointIcon,
    PenIcon,
    PhoneIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Surface } from '@/components/ui/surface';
import { useIsHydrated } from '@/hooks/use-is-hydrated';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { maskCpf, maskPhone } from '@/lib/masks';
import type { Citizen, DuplicateMatch } from '@/types';

const CitizenLocationMap = lazy(
    () => import('@/components/citizens/citizen-location-map'),
);

export default function CitizenShow({
    citizen,
    possibleDuplicates,
    serviceSummary,
    attendanceSummary,
}: {
    citizen: Citizen;
    possibleDuplicates: DuplicateMatch[];
    serviceSummary: {
        total: number;
        open: number;
        completed: number;
        last_interaction: string | null;
    };
    attendanceSummary: {
        total: number;
        last_interaction: string | null;
        recent: Array<{
            id: number;
            assunto: string;
            atendido_em: string;
            requer_retorno: boolean;
            retorno_previsto_em: string | null;
            atendente: { id: number; name: string } | null;
        }>;
    };
}) {
    const tenantUrl = useTenantUrl();

    const contacts = [
        {
            icon: PhoneIcon,
            label: 'Telefone',
            value: citizen.telefone ? maskPhone(citizen.telefone) : null,
        },
        {
            icon: ChatRoundIcon,
            label: 'WhatsApp',
            value: citizen.whatsapp ? maskPhone(citizen.whatsapp) : null,
        },
        { icon: LetterIcon, label: 'E-mail', value: citizen.email },
    ];
    const isHydrated = useIsHydrated();
    const latitude = Number(citizen.latitude);
    const longitude = Number(citizen.longitude);
    const coordinates =
        citizen.latitude !== null &&
        citizen.longitude !== null &&
        Number.isFinite(latitude) &&
        Number.isFinite(longitude)
            ? { latitude, longitude }
            : null;

    return (
        <>
            <Head title={citizen.nome} />
            <PageContainer>
                <PageHeader
                    title={citizen.nome}
                    description="Perfil, contato e histórico de atendimento."
                    actions={
                        <Button asChild>
                            <Link
                                href={tenantUrl(`/cidadaos/${citizen.id}/edit`)}
                            >
                                <PenIcon />
                                Editar cadastro
                            </Link>
                        </Button>
                    }
                />
                {possibleDuplicates.length > 0 && (
                    <section className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950">
                        <div className="flex gap-3">
                            <DangerTriangleIcon className="mt-0.5 size-5" />
                            <div>
                                <h2 className="font-semibold">
                                    Possíveis cadastros semelhantes
                                </h2>
                                {possibleDuplicates.map((item) => (
                                    <p key={item.id} className="mt-1 text-sm">
                                        <Link
                                            className="underline"
                                            href={tenantUrl(
                                                `/cidadaos/${item.id}`,
                                            )}
                                        >
                                            {item.nome}
                                        </Link>{' '}
                                        — coincidência em{' '}
                                        {item.matches.join(', ')}
                                    </p>
                                ))}
                            </div>
                        </div>
                    </section>
                )}
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-6">
                        <Surface as="section">
                            <div className="border-b p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Dados pessoais
                                </h2>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <Badge
                                        variant={
                                            citizen.eleitor
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {citizen.eleitor
                                            ? 'Eleitor do vereador'
                                            : 'Não eleitor'}
                                    </Badge>
                                    <Badge
                                        variant={
                                            citizen.consentimento_contato
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {citizen.consentimento_contato
                                            ? 'Contato autorizado'
                                            : 'Sem consentimento'}
                                    </Badge>
                                </div>
                            </div>
                            <dl className="grid gap-5 p-5 sm:grid-cols-2">
                                {contacts.map(
                                    ({ icon: Icon, label, value }) => (
                                        <div key={label}>
                                            <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                                                <Icon className="size-4" />
                                                {label}
                                            </dt>
                                            <dd className="mt-1 text-sm font-medium">
                                                {value ?? 'Não informado'}
                                            </dd>
                                        </div>
                                    ),
                                )}
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        CPF
                                    </dt>
                                    <dd className="mt-1 text-sm font-medium">
                                        {citizen.cpf
                                            ? maskCpf(citizen.cpf)
                                            : 'Não informado'}
                                    </dd>
                                </div>
                                <div className="sm:col-span-2">
                                    <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <MapPointIcon className="size-4" />
                                        Endereço
                                    </dt>
                                    <dd className="mt-1 text-sm font-medium">
                                        {[
                                            citizen.endereco,
                                            citizen.numero,
                                            citizen.complemento,
                                            citizen.bairro?.nome,
                                        ]
                                            .filter(Boolean)
                                            .join(', ') || 'Não informado'}
                                    </dd>
                                </div>
                                {citizen.observacoes && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-xs text-muted-foreground">
                                            Observações
                                        </dt>
                                        <dd className="mt-1 text-sm whitespace-pre-wrap">
                                            {citizen.observacoes}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            {coordinates && (
                                <div className="space-y-3 border-t p-5">
                                    <div>
                                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                                            <MapPointIcon className="size-4 text-primary" />
                                            Localização residencial
                                        </h3>
                                        {citizen.localizacao_origem ===
                                            'logradouro' && (
                                            <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                Localização aproximada na rua.
                                            </p>
                                        )}
                                        {citizen.localizacao_origem ===
                                            'municipio' && (
                                            <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                Localização aproximada no
                                                município.
                                            </p>
                                        )}
                                    </div>
                                    {isHydrated ? (
                                        <Suspense
                                            fallback={
                                                <div className="grid h-72 place-items-center rounded-2xl border bg-muted text-sm text-muted-foreground">
                                                    Carregando mapa...
                                                </div>
                                            }
                                        >
                                            <CitizenLocationMap
                                                coordinates={coordinates}
                                            />
                                        </Suspense>
                                    ) : (
                                        <div className="grid h-72 place-items-center rounded-2xl border bg-muted text-sm text-muted-foreground">
                                            Carregando mapa...
                                        </div>
                                    )}
                                </div>
                            )}
                        </Surface>
                        <Surface as="section" className="overflow-hidden">
                            <div className="flex flex-wrap items-start justify-between gap-3 border-b p-4">
                                <div>
                                    <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-foreground uppercase">
                                        <HandShakeIcon className="size-4 text-primary" />
                                        Atendimentos presenciais
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        {attendanceSummary.total === 0
                                            ? 'Nenhuma visita registrada.'
                                            : `${attendanceSummary.total} atendimento(s) registrado(s).`}
                                    </p>
                                </div>
                                <Button size="sm" asChild>
                                    <Link
                                        href={tenantUrl(
                                            `/atendimentos/create?cidadao_id=${citizen.id}`,
                                        )}
                                    >
                                        Registrar atendimento
                                    </Link>
                                </Button>
                            </div>
                            {attendanceSummary.recent.length > 0 && (
                                <div className="p-5">
                                    <div className="divide-y rounded-xl border">
                                        {attendanceSummary.recent.map(
                                            (attendance) => (
                                                <Link
                                                    key={attendance.id}
                                                    href={tenantUrl(
                                                        `/atendimentos/${attendance.id}`,
                                                    )}
                                                    className="flex items-center justify-between gap-4 p-3 transition-colors hover:bg-muted"
                                                >
                                                    <span className="min-w-0">
                                                        <strong className="block truncate text-sm">
                                                            {attendance.assunto}
                                                        </strong>
                                                        <span className="text-xs text-muted-foreground">
                                                            {attendance
                                                                .atendente
                                                                ?.name ??
                                                                'Usuário removido'}
                                                        </span>
                                                    </span>
                                                    <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                                        <CalendarMarkIcon className="size-4" />
                                                        {new Intl.DateTimeFormat(
                                                            'pt-BR',
                                                            {
                                                                dateStyle:
                                                                    'short',
                                                                timeStyle:
                                                                    'short',
                                                            },
                                                        ).format(
                                                            new Date(
                                                                attendance.atendido_em,
                                                            ),
                                                        )}
                                                    </span>
                                                </Link>
                                            ),
                                        )}
                                    </div>
                                </div>
                            )}
                        </Surface>
                    </div>
                    <Surface as="aside" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Resumo de atendimentos
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Será alimentado pelo módulo de demandas.
                            </p>
                        </div>
                        <dl className="grid grid-cols-3 gap-2 p-5 text-center">
                            <div className="rounded-md bg-muted p-3">
                                <dt className="text-xs">Total</dt>
                                <dd className="text-xl font-semibold">
                                    {serviceSummary.total}
                                </dd>
                            </div>
                            <div className="rounded-md bg-muted p-3">
                                <dt className="text-xs">Abertas</dt>
                                <dd className="text-xl font-semibold">
                                    {serviceSummary.open}
                                </dd>
                            </div>
                            <div className="rounded-md bg-muted p-3">
                                <dt className="text-xs">Concluídas</dt>
                                <dd className="text-xl font-semibold">
                                    {serviceSummary.completed}
                                </dd>
                            </div>
                        </dl>
                    </Surface>
                </div>
            </PageContainer>
        </>
    );
}

CitizenShow.layout = (page: { citizen: Citizen }) => ({
    breadcrumbs: [
        { title: 'Cidadãos', href: '/cidadaos' },
        { title: page.citizen.nome, href: '#' },
    ],
});
