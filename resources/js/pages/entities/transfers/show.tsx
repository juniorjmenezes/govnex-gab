import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FieldError } from '@/components/forms/field-error';
import { CheckCircleIcon, CloseCircleIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { checkRequiredFields } from '@/lib/required-fields';

type Entidade = {
    id: number;
    name: string;
    slug: string;
    type_label: string;
    city: string;
    state: string;
};

type Transfer = {
    id: string;
    status: string;
    status_label: string;
    gabinete: { id: number; name: string; slug: string };
    source: Entidade;
    destination: Entidade;
    created_at: string;
    accepted_destination_at: string | null;
    completed_at: string | null;
    closed_at: string | null;
    closing_reason: string | null;
    manifest_hash: string | null;
    events: Array<{
        id: number;
        event_label: string;
        tone: 'positive' | 'negative';
        previous_status_label: string | null;
        next_status_label: string | null;
        actor_name: string | null;
        occurred_at: string;
    }>;
};

export default function EntidadeTransferShow({
    entidade,
    transfer,
    canAccept,
    canCancel,
    canReject,
    canApprove,
}: {
    entidade: Entidade;
    transfer: Transfer;
    canAccept: boolean;
    canCancel: boolean;
    canReject: boolean;
    canApprove: boolean;
}) {
    const closeForm = useForm({ reason: '' });
    const closeTransfer = (event: FormEvent) => {
        event.preventDefault();

        if (
            !checkRequiredFields(closeForm.data, closeForm, {
                reason: 'Informe o motivo.',
            })
        ) {
            return;
        }

        closeForm.post(
            `/entidades/${entidade.slug}/transferencias/${transfer.id}/encerrar`,
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={`Transferência - ${transfer.gabinete.name}`} />
            <PageContainer>
                <PageHeader
                    title={transfer.gabinete.name}
                    description={`${transfer.source.name} → ${transfer.destination.name}`}
                    actions={
                        <Badge variant="outline">{transfer.status_label}</Badge>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="flex flex-col gap-6">
                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle>Aprovações</SurfaceTitle>
                            </SurfaceHeader>
                            <dl className="grid gap-4 p-4 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Origem
                                    </dt>
                                    <dd className="font-medium">
                                        Aceita na solicitação
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Destino
                                    </dt>
                                    <dd className="font-medium">
                                        {transfer.accepted_destination_at
                                            ? 'Aceita'
                                            : 'Pendente'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Plataforma
                                    </dt>
                                    <dd className="font-medium">
                                        {transfer.completed_at
                                            ? 'Aprovada'
                                            : 'Pendente'}
                                    </dd>
                                </div>
                            </dl>
                        </Surface>

                        <Surface as="section" className="overflow-hidden">
                            <SurfaceHeader>
                                <SurfaceTitle>Histórico</SurfaceTitle>
                            </SurfaceHeader>
                            <ol className="flex flex-col gap-4 p-4">
                                {transfer.events.map((event) => {
                                    const EventIcon =
                                        event.tone === 'negative'
                                            ? CloseCircleIcon
                                            : CheckCircleIcon;

                                    return (
                                        <li
                                            key={event.id}
                                            className="flex items-start gap-3 text-sm"
                                        >
                                            <EventIcon
                                                className={`mt-0.5 size-4 shrink-0 ${
                                                    event.tone === 'negative'
                                                        ? 'text-destructive'
                                                        : 'text-emerald-600 dark:text-emerald-400'
                                                }`}
                                                aria-hidden="true"
                                            />
                                            <div className="min-w-0">
                                                <p className="font-medium">
                                                    {event.event_label}
                                                </p>
                                                {event.previous_status_label && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {
                                                            event.previous_status_label
                                                        }{' '}
                                                        →{' '}
                                                        {
                                                            event.next_status_label
                                                        }
                                                    </p>
                                                )}
                                                <p className="text-xs text-muted-foreground">
                                                    {event.actor_name ??
                                                        'Sistema'}{' '}
                                                    ·{' '}
                                                    {new Date(
                                                        event.occurred_at,
                                                    ).toLocaleString('pt-BR')}
                                                </p>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ol>
                        </Surface>
                    </div>

                    <aside className="flex flex-col gap-6">
                        {(canAccept || canCancel || canReject) && (
                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader>
                                    <SurfaceTitle>
                                        {canAccept
                                            ? 'Aceite do destino'
                                            : canCancel
                                              ? 'Cancelar'
                                              : 'Recusar'}
                                    </SurfaceTitle>
                                </SurfaceHeader>
                                <div className="flex flex-col gap-4 p-4">
                                    {canAccept && (
                                        <Button
                                            className="w-full"
                                            onClick={() =>
                                                router.post(
                                                    `/entidades/${entidade.slug}/transferencias/${transfer.id}/aceitar`,
                                                )
                                            }
                                        >
                                            Aceitar transferência
                                        </Button>
                                    )}

                                    {(canCancel || canReject) && (
                                        <form
                                            noValidate
                                            className="flex flex-col gap-3"
                                            onSubmit={closeTransfer}
                                        >
                                            <div className="space-y-1">
                                                <Label htmlFor="closing-reason">
                                                    Motivo
                                                </Label>
                                                <Input
                                                    id="closing-reason"
                                                    value={
                                                        closeForm.data.reason
                                                    }
                                                    onChange={(event) =>
                                                        closeForm.setData(
                                                            'reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-required="true"
                                                />
                                                <FieldError
                                                    message={
                                                        closeForm.errors.reason
                                                    }
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                className="w-full"
                                                disabled={closeForm.processing}
                                            >
                                                {canCancel
                                                    ? 'Cancelar transferência'
                                                    : 'Recusar transferência'}
                                            </Button>
                                        </form>
                                    )}
                                </div>
                            </Surface>
                        )}

                        {canApprove && (
                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader>
                                    <SurfaceTitle>
                                        Aprovação da plataforma
                                    </SurfaceTitle>
                                </SurfaceHeader>
                                <div className="p-4">
                                    <Button
                                        className="w-full"
                                        onClick={() =>
                                            router.post(
                                                `/entidades/${entidade.slug}/transferencias/${transfer.id}/aprovar`,
                                            )
                                        }
                                    >
                                        Aprovar e concluir
                                    </Button>
                                </div>
                            </Surface>
                        )}

                        {transfer.closing_reason && (
                            <Surface as="section" className="overflow-hidden">
                                <SurfaceHeader>
                                    <SurfaceTitle>
                                        Motivo do encerramento
                                    </SurfaceTitle>
                                </SurfaceHeader>
                                <p className="p-4 text-sm text-muted-foreground">
                                    {transfer.closing_reason}
                                </p>
                            </Surface>
                        )}
                    </aside>
                </div>
            </PageContainer>
        </>
    );
}

EntidadeTransferShow.layout = (page: {
    entidade: Entidade;
    transfer: Transfer;
}) => ({
    breadcrumbs: [
        { title: 'Entidades', href: '/entidades' },
        {
            title: 'Transferências',
            href: `/entidades/${page.entidade.slug}/transferencias`,
        },
        { title: page.transfer.gabinete.name, href: '#' },
    ],
});
