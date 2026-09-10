import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DeleteRecordButton } from '@/components/common/delete-record-button';
import { FieldError } from '@/components/forms/field-error';
import { RefreshIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { surfaceClasses } from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

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

    const toggle = (ativo: boolean) => {
        setPending(true);
        router.patch(
            `/admin/fontes-rss/${source.id}`,
            { nome: source.nome, url: source.url, ativo },
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
        <div className="flex flex-wrap items-start gap-3 p-4">
            <div className="min-w-56 flex-1">
                <p className="text-sm font-medium">{source.nome}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {source.url}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {collectedAt
                        ? `Última coleta em ${collectedAt} · ${source.noticias} notícias`
                        : 'Ainda não coletada'}
                </p>
                {source.ultimo_erro && (
                    <p className="mt-1 text-xs text-destructive">
                        {source.ultimo_erro}
                    </p>
                )}
            </div>
            <div className="flex items-center gap-2">
                <Switch
                    checked={source.ativo}
                    onCheckedChange={toggle}
                    disabled={pending}
                    aria-label={`Ativar ${source.nome}`}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={collect}
                    disabled={pending}
                    aria-label={`Coletar ${source.nome} agora`}
                    title="Coletar agora"
                >
                    <RefreshIcon />
                </Button>
                <DeleteRecordButton
                    url={`/admin/fontes-rss/${source.id}`}
                    label={`Remover ${source.nome}`}
                    title={`Remover ${source.nome}?`}
                    description="As notícias já coletadas dessa fonte também são removidas dos painéis."
                />
            </div>
        </div>
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
                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <Card className="gap-0 py-0">
                        {sources.length === 0 ? (
                            <p className="p-6 text-sm text-muted-foreground">
                                Nenhuma fonte cadastrada ainda.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {sources.map((source) => (
                                    <SourceRow
                                        key={source.id}
                                        source={source}
                                    />
                                ))}
                            </div>
                        )}
                    </Card>
                    <form
                        onSubmit={handleSubmit(submit)}
                        className={cn(surfaceClasses, 'overflow-hidden')}
                    >
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Nova fonte
                            </h2>
                        </div>
                        <div className="space-y-4 p-5">
                            <div className="space-y-1">
                                <Label htmlFor="rss-nome">Portal</Label>
                                <Input
                                    id="rss-nome"
                                    placeholder="Ex.: G1 Política"
                                    {...register('nome')}
                                />
                                <FieldError message={errors.nome?.message} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="rss-url">
                                    Endereço do feed
                                </Label>
                                <Input
                                    id="rss-url"
                                    placeholder="https://exemplo.com.br/rss/politica"
                                    {...register('url')}
                                />
                                <FieldError message={errors.url?.message} />
                            </div>
                            <Button className="w-full" disabled={isSubmitting}>
                                Cadastrar fonte
                            </Button>
                        </div>
                    </form>
                </div>
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
