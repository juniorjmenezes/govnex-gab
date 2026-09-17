import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { TableActionButton } from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { FieldError } from '@/components/forms/field-error';
import { FeedIcon, RefreshIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type RssSource = {
    id: number;
    nome: string;
    url: string;
    ativo: boolean;
    ultima_coleta_em: string | null;
    itens_importados: number;
    ultimo_erro: string | null;
    ultimo_erro_em: string | null;
    noticias: number;
};

const schema = z.object({
    nome: z.string().min(1, 'Informe o nome do portal.').max(120),
    url: z.url('Informe o endereço completo do feed.').max(500),
});
type Values = z.infer<typeof schema>;

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('pt-BR', {
              dateStyle: 'short',
              timeStyle: 'short',
          }).format(new Date(value))
        : null;

function SourceRow({ source }: { source: RssSource }) {
    const [pending, setPending] = useState(false);
    const collectedAt = formatDateTime(source.ultima_coleta_em);

    const toggle = () => {
        setPending(true);
        router.patch(
            `/admin/fontes-rss/${source.id}`,
            { nome: source.nome, url: source.url, ativo: !source.ativo },
            { preserveScroll: true, onFinish: () => setPending(false) },
        );
    };

    const collect = () => {
        setPending(true);
        router.post(
            `/admin/fontes-rss/${source.id}/coletar`,
            {},
            { preserveScroll: true, onFinish: () => setPending(false) },
        );
    };

    return (
        <TableRow>
            <TableCell className="w-10">
                <ActivityMark active={source.ativo} />
            </TableCell>
            <TableCell className="min-w-64 whitespace-normal">
                <p className="font-normal">{source.nome}</p>
                {/* O endereço e o erro do feed ficam como subtítulo: em coluna
                    própria, uma URL longa forçaria rolagem horizontal. */}
                <p className="text-xs break-all text-muted-foreground">
                    {source.url}
                </p>
                {source.ultimo_erro && (
                    <p className="text-xs break-words text-destructive">
                        {source.ultimo_erro}
                    </p>
                )}
            </TableCell>
            <TableCell className="whitespace-normal">
                {collectedAt ? (
                    <>
                        <p className="font-normal">{collectedAt}</p>
                        <p className="text-xs text-muted-foreground">
                            {source.noticias} notícias
                        </p>
                    </>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Ainda não coletada
                    </span>
                )}
            </TableCell>
            <TableCell>
                <div className="flex justify-end gap-2">
                    <ActivityToggleButton
                        active={source.ativo}
                        name={source.nome}
                        disabled={pending}
                        onClick={toggle}
                    />
                    <TableActionButton
                        label={`Coletar ${source.nome} agora`}
                        disabled={pending}
                        onClick={collect}
                    >
                        <RefreshIcon aria-hidden="true" />
                    </TableActionButton>
                    <DeleteRecordButton
                        url={`/admin/fontes-rss/${source.id}`}
                        label={`Remover ${source.nome}`}
                        title={`Remover ${source.nome}?`}
                        subject={source.nome}
                        subjectDetail={source.url}
                        description="As notícias já coletadas dessa fonte também são removidas dos painéis."
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}

export default function RssSources({ sources }: { sources: RssSource[] }) {
    const {
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { nome: '', url: '' },
    });

    const submit = (values: Values) =>
        router.post(
            '/admin/fontes-rss',
            { ...values, ativo: true },
            {
                onSuccess: () => reset({ nome: '', url: '' }),
                onError: (items) =>
                    Object.entries(items).forEach(([key, message]) =>
                        setError(key as keyof Values, { message }),
                    ),
            },
        );

    return (
        <>
            <Head title="Fontes de notícias" />
            <PageContainer>
                <PageHeader
                    title="Fontes de notícias"
                    description="Feeds RSS dos portais consultados a cada 15 minutos. As notícias coletadas alimentam o acompanhamento dos candidatos favoritos em todos os gabinetes."
                />

                {/* Cadastro em linha, no topo: são dois campos, e um card
                    lateral só para eles empurrava a tabela para metade da
                    largura. */}
                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Nova fonte</SurfaceTitle>
                    </SurfaceHeader>
                    <form
                        noValidate
                        onSubmit={handleSubmit(submit)}
                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start"
                    >
                        <div className="w-full space-y-1 sm:w-64">
                            <Input
                                aria-label="Portal"
                                placeholder="Ex.: G1 Política"
                                {...register('nome')}
                            />
                            <FieldError message={errors.nome?.message} />
                        </div>
                        <div className="w-full flex-1 space-y-1">
                            <Input
                                aria-label="Endereço do feed"
                                placeholder="https://exemplo.com.br/rss/politica"
                                {...register('url')}
                            />
                            <FieldError message={errors.url?.message} />
                        </div>
                        <Button
                            className="w-full shrink-0 sm:w-auto"
                            disabled={isSubmitting}
                        >
                            Cadastrar fonte
                        </Button>
                    </form>
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    {sources.length === 0 ? (
                        <EmptyState
                            icon={FeedIcon}
                            title="Nenhuma fonte cadastrada"
                            description="Cadastre o primeiro feed acima. A primeira coleta é enfileirada assim que a fonte é salva."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <span className="sr-only">
                                            Situação
                                        </span>
                                    </TableHead>
                                    <TableHead>Portal</TableHead>
                                    <TableHead>Última coleta</TableHead>
                                    <TableHead className="text-right">
                                        Ações
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sources.map((source) => (
                                    <SourceRow
                                        key={source.id}
                                        source={source}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

RssSources.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Fontes de notícias', href: '/admin/fontes-rss' },
    ],
};
