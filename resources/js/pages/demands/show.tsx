import { Head, router } from '@inertiajs/react';
import { HeartIcon as HeartBoldIcon } from '@solar-icons/react/bold';
import {
    CalendarMarkIcon,
    ChatSquareArrowIcon,
    ChatSquareIcon,
    ClipboardCheckIcon,
    CloseIcon,
    HeartIcon as HeartOutlineIcon,
    MapPointIcon,
    UserCheckRoundedIcon,
    UserCircleIcon,
} from '@solar-icons/react/outline';
import { useState } from 'react';
import { DemandStatusActions } from '@/components/demands/demand-status-actions';
import { DemandTimeline } from '@/components/demands/demand-timeline';
import { NextActionPanel } from '@/components/demands/next-action-panel';
import { PriorityBadge } from '@/components/demands/priority-badge';
import { ReferralForm } from '@/components/demands/referral-form';
import { ReferralResponseForm } from '@/components/demands/referral-response-form';
import { StatusBadge } from '@/components/demands/status-badge';
import { UpdateForm } from '@/components/demands/update-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Surface } from '@/components/ui/surface';
import { maskPhone } from '@/lib/masks';
import { cn } from '@/lib/utils';
import type {
    Demand,
    DemandMember,
    PendingReferral,
    SelectOption,
} from '@/types';

const formatDateTime = (date: string | null) => {
    if (!date) {
        return 'Não informado';
    }

    const value = new Date(date);
    const day = new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(value);
    const time = new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(value);

    return `${day}, ${time}`;
};

type SheetKind = 'update' | 'referral' | 'response' | null;

export default function DemandShow({
    demand,
    allowedTransitions,
    resultados,
    pendingReferrals,
    canDelete,
    members,
}: {
    demand: Demand;
    allowedTransitions: SelectOption[];
    resultados: SelectOption[];
    pendingReferrals: PendingReferral[];
    canDelete: boolean;
    members: DemandMember[];
}) {
    const [sheet, setSheet] = useState<SheetKind>(null);
    const address =
        [
            demand.endereco,
            demand.numero,
            demand.complemento,
            demand.bairro?.nome,
        ]
            .filter(Boolean)
            .join(', ') || null;

    return (
        <>
            <Head title={`${demand.protocolo} — ${demand.titulo}`} />
            <PageContainer>
                <PageHeader
                    title={
                        <span className="inline-flex items-center gap-2">
                            {demand.titulo}
                            <FavoriteButton demand={demand} />
                        </span>
                    }
                    description={`Protocolo ${demand.protocolo} · aberta em ${formatDateTime(demand.aberta_em)}`}
                    actions={
                        <>
                            <Button onClick={() => setSheet('update')}>
                                <ChatSquareIcon />
                                Atualizar
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setSheet('referral')}
                            >
                                <ChatSquareArrowIcon />
                                Encaminhar
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setSheet('response')}
                            >
                                <ClipboardCheckIcon />
                                Registrar retorno
                            </Button>
                            <DemandStatusActions
                                demand={demand}
                                allowedTransitions={allowedTransitions}
                                resultados={resultados}
                                canDelete={canDelete}
                            />
                        </>
                    }
                />

                <Surface
                    as="section"
                    className="grid gap-6 p-5 lg:grid-cols-[1fr_auto]"
                >
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Status
                            </dt>
                            <dd className="mt-1">
                                <StatusBadge status={demand.status} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Prioridade
                            </dt>
                            <dd className="mt-1">
                                <PriorityBadge priority={demand.prioridade} />
                            </dd>
                        </div>
                        <Info
                            icon={UserCircleIcon}
                            label="Solicitante"
                            value={demand.cidadao.nome}
                        />
                        <Info
                            icon={UserCheckRoundedIcon}
                            label="Responsável"
                            value={demand.responsavel?.name ?? 'Não atribuído'}
                        />
                        <Info
                            icon={CalendarMarkIcon}
                            label="Prazo"
                            value={
                                demand.atrasada
                                    ? `${formatDateTime(demand.prazo)} · atrasada`
                                    : formatDateTime(demand.prazo)
                            }
                            danger={demand.atrasada}
                        />
                        {demand.categoria && (
                            <Info
                                icon={ChatSquareIcon}
                                label="Categoria"
                                value={demand.categoria.nome ?? '—'}
                            />
                        )}
                        {address && (
                            <Info
                                icon={MapPointIcon}
                                label="Localização"
                                value={address}
                            />
                        )}
                        <Info
                            icon={UserCircleIcon}
                            label="Contato"
                            value={
                                demand.cidadao.whatsapp ||
                                demand.cidadao.telefone
                                    ? maskPhone(
                                          demand.cidadao.whatsapp ??
                                              demand.cidadao.telefone,
                                      )
                                    : 'Não informado'
                            }
                        />
                    </dl>
                </Surface>

                <p className="text-sm leading-6 whitespace-pre-wrap">
                    {demand.descricao}
                </p>

                <Surface as="section" className="overflow-hidden">
                    <div className="border-b p-4">
                        <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                            Próxima ação
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            O que precisa acontecer a seguir nesta demanda.
                        </p>
                    </div>
                    <NextActionPanel demand={demand} members={members} />
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <div className="border-b p-4">
                        <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                            Linha do tempo
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Tudo que aconteceu nesta demanda, em ordem
                            cronológica.
                        </p>
                    </div>
                    <DemandTimeline
                        demandId={demand.id}
                        events={demand.eventos ?? []}
                    />
                </Surface>
            </PageContainer>

            <Drawer
                open={sheet === 'update'}
                onOpenChange={(open) => !open && setSheet(null)}
                swipeDirection="right"
            >
                <DrawerContent side="right">
                    <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                        <DrawerTitle className="text-xs font-semibold tracking-wide uppercase">
                            Adicionar atualização
                        </DrawerTitle>
                        <DrawerClose
                            render={<Button variant="ghost" size="icon-sm" />}
                            aria-label="Fechar"
                        >
                            <CloseIcon aria-hidden="true" />
                        </DrawerClose>
                    </DrawerHeader>
                    <div className="flex min-h-0 flex-1 flex-col">
                        <UpdateForm
                            demandId={demand.id}
                            onDone={() => setSheet(null)}
                        />
                    </div>
                </DrawerContent>
            </Drawer>

            <Drawer
                open={sheet === 'referral'}
                onOpenChange={(open) => !open && setSheet(null)}
                swipeDirection="right"
            >
                <DrawerContent side="right">
                    <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                        <DrawerTitle className="text-xs font-semibold tracking-wide uppercase">
                            Encaminhar
                        </DrawerTitle>
                        <DrawerClose
                            render={<Button variant="ghost" size="icon-sm" />}
                            aria-label="Fechar"
                        >
                            <CloseIcon aria-hidden="true" />
                        </DrawerClose>
                    </DrawerHeader>
                    <div className="flex min-h-0 flex-1 flex-col">
                        <ReferralForm
                            demandId={demand.id}
                            onDone={() => setSheet(null)}
                        />
                    </div>
                </DrawerContent>
            </Drawer>

            <Drawer
                open={sheet === 'response'}
                onOpenChange={(open) => !open && setSheet(null)}
                swipeDirection="right"
            >
                <DrawerContent side="right">
                    <DrawerHeader className="flex-row items-center justify-between border-b p-4">
                        <DrawerTitle className="text-xs font-semibold tracking-wide uppercase">
                            Registrar retorno
                        </DrawerTitle>
                        <DrawerClose
                            render={<Button variant="ghost" size="icon-sm" />}
                            aria-label="Fechar"
                        >
                            <CloseIcon aria-hidden="true" />
                        </DrawerClose>
                    </DrawerHeader>
                    <div className="flex min-h-0 flex-1 flex-col">
                        <ReferralResponseForm
                            demandId={demand.id}
                            pendingReferrals={pendingReferrals}
                            onDone={() => setSheet(null)}
                        />
                    </div>
                </DrawerContent>
            </Drawer>
        </>
    );
}

function FavoriteButton({ demand }: { demand: Demand }) {
    const favorited = demand.favoritada_em !== null;

    return (
        <button
            type="button"
            onClick={() =>
                router.patch(
                    `/demandas/${demand.id}/favorito`,
                    {},
                    { preserveScroll: true },
                )
            }
            aria-pressed={favorited}
            aria-label={
                favorited
                    ? `Remover destaque de ${demand.protocolo}`
                    : `Destacar ${demand.protocolo}`
            }
            title={
                favorited && demand.favoritada_por?.name
                    ? `Destacada por ${demand.favoritada_por.name}`
                    : undefined
            }
            className={cn(
                'normal-case transition-colors hover:text-amber-500 focus-visible:text-amber-500 focus-visible:outline-none',
                favorited ? 'text-amber-500' : 'text-muted-foreground/50',
            )}
        >
            {favorited ? (
                <HeartBoldIcon className="size-4" />
            ) : (
                <HeartOutlineIcon className="size-4" />
            )}
        </button>
    );
}

function Info({
    icon: Icon,
    label,
    value,
    danger = false,
}: {
    icon: typeof CalendarMarkIcon;
    label: string;
    value: string;
    danger?: boolean;
}) {
    return (
        <div>
            <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon className="size-3.5" />
                {label}
            </dt>
            <dd
                className={
                    danger
                        ? 'mt-1 text-sm font-medium text-destructive'
                        : 'mt-1 text-sm font-medium'
                }
            >
                {value}
            </dd>
        </div>
    );
}

DemandShow.layout = (page: { demand: Demand }) => ({
    breadcrumbs: [
        { title: 'Demandas', href: '/demandas' },
        { title: page.demand.protocolo, href: '#' },
    ],
});
