import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { AddIcon, PowerIcon } from '@solar-icons/react/outline';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { FieldError } from '@/components/forms/field-error';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, surfaceClasses } from '@/components/ui/surface';
import { contextualUrl } from '@/lib/entity-context';
import { cn } from '@/lib/utils';
import type { Auth, Neighborhood, Pagination } from '@/types';

const schema = z.object({
    nome: z.string().min(2, 'Informe o bairro.'),
    ativo: z.boolean(),
});
type Values = z.infer<typeof schema>;
type SharedNeighborhood = {
    id: number;
    name: string;
    city: string;
    state: string;
};
export default function Neighborhoods({
    neighborhoods,
    sharedNeighborhoods,
    canManage,
    officeLocation,
}: {
    neighborhoods: Pagination<Neighborhood>;
    sharedNeighborhoods: SharedNeighborhood[];
    canManage: boolean;
    officeLocation: { municipio: string; estado: string };
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const tenantUrl = (path: string) => contextualUrl(auth, path);
    const {
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { nome: '', ativo: true },
    });
    const submit = (values: Values) =>
        router.post(tenantUrl('/bairros'), values, {
            onSuccess: () => reset({ nome: '', ativo: true }),

            onError: (items) =>
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });

    return (
        <>
            <Head title="Bairros" />
            <PageContainer>
                <PageHeader
                    title="Bairros"
                    description="Mantenha a área territorial usada nos cadastros e relatórios."
                />
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card className="gap-0 py-0">
                        <div className="divide-y">
                            {neighborhoods.data.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center justify-between gap-4 p-4"
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="font-medium">
                                                {item.nome}
                                            </h2>
                                            <Badge
                                                variant={
                                                    item.ativo
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {item.ativo
                                                    ? 'Ativo'
                                                    : 'Inativo'}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {item.municipio}/{item.estado}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="ml-auto flex justify-end gap-2">
                                            <TableActionButton
                                                label={`${item.ativo ? 'Desativar' : 'Ativar'} ${item.nome}`}
                                                variant={
                                                    item.ativo
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    router.put(
                                                        tenantUrl(
                                                            `/bairros/${item.id}`,
                                                        ),
                                                        {
                                                            ...item,
                                                            ativo: !item.ativo,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <PowerIcon aria-hidden="true" />
                                            </TableActionButton>
                                            <DeleteRecordButton
                                                url={tenantUrl(
                                                    `/bairros/${item.id}`,
                                                )}
                                                label={`Excluir ${item.nome}`}
                                                title="Excluir bairro?"
                                                description="O registro deixará de aparecer nos novos cadastros. Os vínculos históricos serão preservados."
                                            />
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                        <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                            Exibindo {neighborhoods.from}–{neighborhoods.to} de{' '}
                            {neighborhoods.total} bairro(s)
                        </div>
                        <PaginationLinks links={neighborhoods.links} />
                    </Card>
                    {canManage && (
                        <div className="space-y-6">
                            <form
                                onSubmit={handleSubmit(submit)}
                                className={cn(surfaceClasses, 'space-y-4 p-5')}
                            >
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Novo bairro
                                </h2>
                                <div className="rounded-md bg-muted/50 p-3">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Localização do gabinete
                                    </p>
                                    <p className="mt-1 text-sm font-medium">
                                        {officeLocation.municipio}/
                                        {officeLocation.estado}
                                    </p>
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="neighborhood-name">
                                        Nome
                                    </Label>
                                    <Input
                                        id="neighborhood-name"
                                        {...register('nome')}
                                    />
                                    <FieldError
                                        message={errors.nome?.message}
                                    />
                                </div>
                                <Button
                                    className="w-full"
                                    disabled={isSubmitting}
                                >
                                    Cadastrar bairro
                                </Button>
                            </form>

                            <Surface as="section" className="p-5">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Referências da entidade
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Adicione ao gabinete bairros já mantidos
                                    pela entidade.
                                </p>
                                <div className="mt-4 space-y-2">
                                    {sharedNeighborhoods.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Todas as referências ativas já estão
                                            disponíveis neste gabinete.
                                        </p>
                                    ) : (
                                        sharedNeighborhoods.map((item) => (
                                            <div
                                                key={item.id}
                                                className="flex items-center gap-3 rounded-md border p-3"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {item.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {item.city}/{item.state}
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="outline"
                                                    title={`Adicionar ${item.name}`}
                                                    onClick={() =>
                                                        router.post(
                                                            tenantUrl(
                                                                `/bairros/referencias/${item.id}`,
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <AddIcon className="size-4" />
                                                    <span className="sr-only">
                                                        Adicionar
                                                    </span>
                                                </Button>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </Surface>
                        </div>
                    )}
                </div>
            </PageContainer>
        </>
    );
}

Neighborhoods.layout = {
    breadcrumbs: [{ title: 'Bairros', href: '/bairros' }],
};
