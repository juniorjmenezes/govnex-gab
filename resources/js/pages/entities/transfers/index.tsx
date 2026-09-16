import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PaginationLinks } from '@/components/common/pagination-links';
import { FieldError } from '@/components/forms/field-error';
import { ArrowRightIcon, RefreshCircleIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { checkRequiredFields } from '@/lib/required-fields';
import type { Pagination } from '@/types';

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
};

export default function EntidadeTransfersIndex({
    entidade,
    gabinetes,
    destinations,
    transfers,
    canRequest,
}: {
    entidade: Entidade;
    gabinetes: Array<{ id: number; name: string; type_label: string }>;
    destinations: Entidade[];
    transfers: Pagination<Transfer>;
    canRequest: boolean;
    isRoot: boolean;
}) {
    const form = useForm({ gabinete_id: '', destination_id: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (
            !checkRequiredFields(form.data, form, {
                gabinete_id: 'Selecione o gabinete.',
                destination_id: 'Selecione a entidade de destino.',
            })
        ) {
            return;
        }

        form.post(`/entidades/${entidade.slug}/transferencias`);
    };

    return (
        <>
            <Head title={`Transferências - ${entidade.name}`} />
            <PageContainer>
                <PageHeader
                    title="Transferências de gabinetes"
                    description={`${entidade.city}/${entidade.state}`}
                />

                {canRequest && (
                    <Surface as="section" className="overflow-hidden">
                        <SurfaceHeader>
                            <SurfaceTitle>Nova transferência</SurfaceTitle>
                        </SurfaceHeader>
                        <form
                            noValidate
                            className="grid gap-4 p-4 md:grid-cols-[1fr_1fr_auto]"
                            onSubmit={submit}
                        >
                            <div className="flex flex-col gap-2">
                                <Label>Gabinete</Label>
                                <AppSelect
                                    value={form.data.gabinete_id}
                                    onValueChange={(value) =>
                                        form.setData('gabinete_id', value)
                                    }
                                    placeholder="Selecione"
                                    options={gabinetes.map((gabinete) => ({
                                        value: String(gabinete.id),
                                        label: `${gabinete.name} · ${gabinete.type_label}`,
                                    }))}
                                />
                                <FieldError message={form.errors.gabinete_id} />
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label>Entidade de destino</Label>
                                <AppSelect
                                    value={form.data.destination_id}
                                    onValueChange={(value) =>
                                        form.setData('destination_id', value)
                                    }
                                    placeholder="Selecione"
                                    options={destinations.map(
                                        (destination) => ({
                                            value: String(destination.id),
                                            label: destination.name,
                                        }),
                                    )}
                                />
                                <FieldError
                                    message={form.errors.destination_id}
                                />
                            </div>
                            <div className="flex md:flex-col md:justify-end">
                                <Button
                                    type="submit"
                                    className="w-full md:w-auto"
                                    disabled={form.processing}
                                >
                                    Solicitar
                                    <ArrowRightIcon className="size-4" />
                                </Button>
                            </div>
                        </form>
                    </Surface>
                )}

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader
                        actions={
                            <Badge variant="outline" className="shrink-0">
                                {transfers.data.length}
                            </Badge>
                        }
                    >
                        <SurfaceTitle>Histórico</SurfaceTitle>
                    </SurfaceHeader>
                    {transfers.data.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            Nenhuma transferência registrada.
                        </p>
                    ) : (
                        <>
                            <div className="divide-y">
                                {transfers.data.map((transfer) => (
                                    <Link
                                        key={transfer.id}
                                        href={`/entidades/${entidade.slug}/transferencias/${transfer.id}`}
                                        className="flex min-h-20 items-center gap-4 p-4 transition-colors hover:bg-muted/40"
                                    >
                                        <RefreshCircleIcon className="size-5 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {transfer.gabinete.name}
                                            </p>
                                            <p className="truncate text-sm text-muted-foreground">
                                                {transfer.source.name} →{' '}
                                                {transfer.destination.name}
                                            </p>
                                        </div>
                                        <Badge variant="outline">
                                            {transfer.status_label}
                                        </Badge>
                                    </Link>
                                ))}
                            </div>
                            <PaginationLinks
                                pagination={transfers}
                                label="transferência(s)"
                            />
                        </>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

EntidadeTransfersIndex.layout = {
    breadcrumbs: [
        { title: 'Entidades', href: '/entidades' },
        { title: 'Transferências', href: '#' },
    ],
};
