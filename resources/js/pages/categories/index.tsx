import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { PowerIcon } from '@solar-icons/react/outline';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import {
    CategoryIconBadge,
    CategorySemanticBadge,
    categoryColorOptions,
    categoryIconOptions,
} from '@/components/categories/category-appearance';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { PaginationLinks } from '@/components/common/pagination-links';
import { TableActionButton } from '@/components/common/table-action-button';
import { FieldError } from '@/components/forms/field-error';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { surfaceClasses } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Category, Pagination } from '@/types';
const schema = z.object({
    nome: z.string().min(2, 'Informe o nome.'),
    descricao: z.string(),
    icone: z.string(),
    cor_semantica: z.enum([
        'neutra',
        'informativa',
        'sucesso',
        'atencao',
        'critica',
    ]),
    ativo: z.boolean(),
});
type Values = z.infer<typeof schema>;
export default function Categories({
    categories,
    canManage,
}: {
    categories: Pagination<Category>;
    canManage: boolean;
}) {
    const {
        control,
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            nome: '',
            descricao: '',
            icone: 'tag',
            cor_semantica: 'neutra',
            ativo: true,
        },
    });
    const selectedColor = useWatch({ control, name: 'cor_semantica' });
    const submit = (values: Values) =>
        router.post('/categorias', values, {
            onSuccess: () => reset(),
            onError: (items) =>
                Object.entries(items).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        });

    return (
        <>
            <Head title="Categorias" />
            <PageContainer>
                <PageHeader
                    title="Categorias"
                    description="Organize as futuras demandas por tema, cor e situação."
                />
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card className="gap-0 py-0">
                        <div className="divide-y">
                            {categories.data.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-start justify-between gap-4 p-4"
                                >
                                    <div className="flex min-w-0 items-start gap-3">
                                        <CategoryIconBadge
                                            name={item.icone}
                                            color={item.cor_semantica}
                                        />
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
                                                        ? 'Ativa'
                                                        : 'Inativa'}
                                                </Badge>
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {item.descricao ||
                                                    'Sem descrição'}
                                            </p>
                                        </div>
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
                                                        `/categorias/${item.id}`,
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
                                                url={`/categorias/${item.id}`}
                                                label={`Excluir ${item.nome}`}
                                                title="Excluir categoria?"
                                                description="O registro deixará de aparecer nos novos cadastros. Os vínculos históricos serão preservados."
                                            />
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                        <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                            Exibindo {categories.from}–{categories.to} de{' '}
                            {categories.total} categoria(s)
                        </div>
                        <PaginationLinks links={categories.links} />
                    </Card>
                    {canManage && (
                        <form
                            onSubmit={handleSubmit(submit)}
                            className={cn(surfaceClasses, 'overflow-hidden')}
                        >
                            <div className="border-b p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                    Nova categoria
                                </h2>
                            </div>
                            <div className="space-y-4 p-5">
                                <div className="space-y-1">
                                    <Label htmlFor="category-name">Nome</Label>
                                    <Input
                                        id="category-name"
                                        {...register('nome')}
                                    />
                                    <FieldError
                                        message={errors.nome?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="category-description">
                                        Descrição
                                    </Label>
                                    <Textarea
                                        id="category-description"
                                        {...register('descricao')}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="category-icon">Ícone</Label>
                                    <Controller
                                        control={control}
                                        name="icone"
                                        render={({ field }) => (
                                            <div className="flex items-center gap-3">
                                                <CategoryIconBadge
                                                    name={field.value}
                                                    color={selectedColor}
                                                />
                                                <AppSelect
                                                    id="category-icon"
                                                    value={field.value}
                                                    onValueChange={
                                                        field.onChange
                                                    }
                                                    options={categoryIconOptions.map(
                                                        (option) => ({
                                                            ...option,
                                                        }),
                                                    )}
                                                />
                                            </div>
                                        )}
                                    />
                                    <FieldError
                                        message={errors.icone?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="category-color">
                                        Cor semântica
                                    </Label>
                                    <Controller
                                        control={control}
                                        name="cor_semantica"
                                        render={({ field }) => (
                                            <AppSelect
                                                id="category-color"
                                                value={field.value}
                                                onValueChange={field.onChange}
                                                options={categoryColorOptions}
                                            />
                                        )}
                                    />
                                    <CategorySemanticBadge
                                        color={selectedColor}
                                        className="mt-2"
                                    />
                                    <FieldError
                                        message={errors.cor_semantica?.message}
                                    />
                                </div>
                                <Button
                                    className="w-full"
                                    disabled={isSubmitting}
                                >
                                    Cadastrar categoria
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </PageContainer>
        </>
    );
}

Categories.layout = {
    breadcrumbs: [{ title: 'Categorias', href: '/categorias' }],
};
