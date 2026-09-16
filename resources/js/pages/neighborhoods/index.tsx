import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { EmptyState } from '@/components/feedback/empty-state';
import { FieldError } from '@/components/forms/field-error';
import { AddIcon, MapPointIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { contextualUrl } from '@/lib/entity-context';
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

                {canManage && (
                    <Surface as="section" className="overflow-hidden">
                        {/* O município não é escolhido no cadastro: vem do
                            gabinete. Como subtítulo do card ele informa sem
                            ocupar uma caixa própria na linha do formulário. */}
                        <SurfaceHeader>
                            <SurfaceTitle>Novo bairro</SurfaceTitle>
                            <SurfaceDescription>
                                {officeLocation.municipio}/
                                {officeLocation.estado}
                            </SurfaceDescription>
                        </SurfaceHeader>
                        <form
                            noValidate
                            onSubmit={handleSubmit(submit)}
                            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start"
                        >
                            <div className="w-full flex-1 space-y-1">
                                <Input
                                    aria-label="Nome do bairro"
                                    placeholder="Ex.: Centro"
                                    {...register('nome')}
                                />
                                <FieldError message={errors.nome?.message} />
                            </div>
                            <Button
                                className="w-full shrink-0 sm:w-auto"
                                disabled={isSubmitting}
                            >
                                Cadastrar bairro
                            </Button>
                        </form>
                    </Surface>
                )}

                <Surface as="section" className="overflow-hidden">
                    {neighborhoods.data.length === 0 ? (
                        <EmptyState
                            icon={MapPointIcon}
                            title="Nenhum bairro cadastrado"
                            description="Cadastre o primeiro bairro ou traga uma referência já mantida pela entidade."
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Situação
                                            </span>
                                        </TableHead>
                                        <TableHead>Nome</TableHead>
                                        <TableHead>Município/UF</TableHead>
                                        {canManage && (
                                            <TableHead className="text-right">
                                                Ações
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {neighborhoods.data.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="w-10">
                                                <ActivityMark
                                                    active={item.ativo}
                                                />
                                            </TableCell>
                                            <TableCell>{item.nome}</TableCell>
                                            <TableCell>
                                                {item.municipio}/{item.estado}
                                            </TableCell>
                                            {canManage && (
                                                <TableCell>
                                                    <div className="flex justify-end gap-2">
                                                        <ActivityToggleButton
                                                            active={item.ativo}
                                                            name={item.nome}
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
                                                        />
                                                        <DeleteRecordButton
                                                            url={tenantUrl(
                                                                `/bairros/${item.id}`,
                                                            )}
                                                            label={`Excluir ${item.nome}`}
                                                            title="Excluir bairro?"
                                                            description="O registro deixará de aparecer nos novos cadastros. Os vínculos históricos serão preservados."
                                                        />
                                                    </div>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <PaginationLinks
                                pagination={neighborhoods}
                                label="bairro(s)"
                            />
                        </>
                    )}
                </Surface>

                {canManage && (
                    <Surface as="section" className="overflow-hidden">
                        <SurfaceHeader help="Adicione ao gabinete bairros já mantidos pela entidade.">
                            <SurfaceTitle>Referências da entidade</SurfaceTitle>
                        </SurfaceHeader>
                        {sharedNeighborhoods.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Todas as referências ativas já estão disponíveis
                                neste gabinete.
                            </p>
                        ) : (
                            // Em largura total a pilha vertical deixaria uma
                            // faixa vazia à direita; a grade acompanha o card.
                            <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                {sharedNeighborhoods.map((item) => (
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
                                ))}
                            </div>
                        )}
                    </Surface>
                )}
            </PageContainer>
        </>
    );
}

Neighborhoods.layout = {
    breadcrumbs: [{ title: 'Bairros', href: '/bairros' }],
};
