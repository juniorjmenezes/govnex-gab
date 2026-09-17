import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DestructiveAlertDialog } from '@/components/common/destructive-alert-dialog';
import { FieldError } from '@/components/forms/field-error';
import {
    CheckCircleIcon,
    DangerCircleIcon,
    InfoCircleIcon,
    KeyIcon,
    PlugCircleIcon,
    RefreshIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';

type Integration = {
    url: string;
    has_key: boolean;
    key_hint: string | null;
    from_env: boolean;
    verified_at: string | null;
    verified_result: string | null;
    verified_detail: string | null;
    updated_by: string | null;
    updated_at: string | null;
};

const schema = z.object({
    url: z
        .string()
        .min(1, 'Informe a URL base da GOVNEX API.')
        .url('Use uma URL completa, com http:// ou https://.'),
    chave: z
        .string()
        .refine(
            (value) => value === '' || value.length >= 16,
            'A chave gerada pela GOVNEX API tem pelo menos 16 caracteres.',
        ),
});
type Values = z.infer<typeof schema>;

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

export default function GovnexApiIntegration({
    integration,
}: {
    integration: Integration;
}) {
    const [testing, setTesting] = useState(false);
    const [removeOpen, setRemoveOpen] = useState(false);
    const [removing, setRemoving] = useState(false);
    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { url: integration.url, chave: '' },
    });

    const submit = (values: Values) => {
        router.put('/admin/integracoes/govnex-api', values, {
            preserveScroll: true,
            onSuccess: () => reset({ url: values.url, chave: '' }),
        });
    };

    const removeKey = () => {
        setRemoving(true);
        router.put(
            '/admin/integracoes/govnex-api',
            { url: integration.url, chave: '', remover_chave: true },
            {
                preserveScroll: true,
                onSuccess: () => setRemoveOpen(false),
                onFinish: () => setRemoving(false),
            },
        );
    };

    const test = () => {
        setTesting(true);
        router.post(
            '/admin/integracoes/govnex-api/testar',
            {},
            { preserveScroll: true, onFinish: () => setTesting(false) },
        );
    };

    const ok = integration.verified_result === 'ok';

    return (
        <>
            <Head title="Integração GOVNEX API" />
            <PageContainer>
                <PageHeader
                    title="Integração GOVNEX API"
                    description="Endereço e credencial usados pelas sincronizações de eleitorado. Valem para toda a plataforma, não por gabinete."
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={test}
                            disabled={testing}
                        >
                            <PlugCircleIcon aria-hidden="true" />
                            {testing ? 'Testando...' : 'Testar conexão'}
                        </Button>
                    }
                />

                {integration.from_env && (
                    <Alert variant="info">
                        <InfoCircleIcon />
                        <AlertTitle>Usando valores do .env</AlertTitle>
                        <AlertDescription>
                            Nenhuma configuração salva ainda. Os valores abaixo
                            vêm de <code>GOVNEX_API_URL</code> e{' '}
                            <code>GOVNEX_API_KEY</code>; o que for salvo aqui
                            passa a valer no lugar deles.
                        </AlertDescription>
                    </Alert>
                )}

                {integration.verified_at && (
                    <Alert variant={ok ? 'success' : 'destructive'}>
                        {ok ? <CheckCircleIcon /> : <DangerCircleIcon />}
                        <AlertTitle>
                            {integration.verified_detail ??
                                (ok
                                    ? 'Conexão verificada'
                                    : 'Falha ao verificar a conexão')}
                        </AlertTitle>
                        <AlertDescription>
                            Última verificação em{' '}
                            {dateTime.format(new Date(integration.verified_at))}
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="gap-0 py-0">
                    <SurfaceHeader help="Inclua na URL o caminho até a versão da API, terminando em /api/v1. A chave é gerada em Chaves de API, no painel da GOVNEX API.">
                        <SurfaceTitle>Conexão</SurfaceTitle>
                    </SurfaceHeader>
                    <form noValidate onSubmit={handleSubmit(submit)}>
                        <div className="grid gap-5 p-5 md:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="url">
                                    URL base <span aria-hidden="true">*</span>
                                </Label>
                                <Input
                                    id="url"
                                    type="url"
                                    aria-required="true"
                                    placeholder="http://127.0.0.1:8010/api/v1"
                                    {...register('url')}
                                />
                                <FieldError message={errors.url?.message} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="chave">
                                    {integration.has_key
                                        ? 'Nova chave de acesso'
                                        : 'Chave de acesso'}
                                </Label>
                                <Input
                                    id="chave"
                                    type="password"
                                    autoComplete="off"
                                    placeholder={
                                        integration.has_key
                                            ? 'Em branco mantém a chave atual'
                                            : 'Cole a chave gerada na GOVNEX API'
                                    }
                                    {...register('chave')}
                                />
                                <FieldError message={errors.chave?.message} />
                            </div>

                            <div className="md:col-span-2">
                                {integration.has_key ? (
                                    <div className="flex flex-col gap-3 rounded-md border bg-muted/40 p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                                <KeyIcon
                                                    className="size-5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">
                                                    Chave de acesso configurada
                                                    {integration.key_hint && (
                                                        <code className="ml-2 rounded-sm bg-background px-1.5 py-0.5 text-xs font-normal">
                                                            {
                                                                integration.key_hint
                                                            }
                                                        </code>
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    O valor não é exibido de
                                                    volta. Informe uma nova
                                                    chave acima para
                                                    substituí-la.
                                                </p>
                                            </div>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="shrink-0"
                                            onClick={() => setRemoveOpen(true)}
                                        >
                                            Remover chave
                                        </Button>
                                    </div>
                                ) : (
                                    <Alert>
                                        <KeyIcon />
                                        <AlertTitle>
                                            Nenhuma chave configurada
                                        </AlertTitle>
                                        <AlertDescription>
                                            Sem chave, a API responde com o
                                            limite do consumidor anônimo: 60
                                            requisições por minuto e per_page de
                                            até 100.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-col-reverse gap-3 border-t p-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-xs text-muted-foreground">
                                {integration.updated_at
                                    ? `Atualizado em ${dateTime.format(new Date(integration.updated_at))}${
                                          integration.updated_by
                                              ? ` por ${integration.updated_by}`
                                              : ''
                                      }`
                                    : 'Ainda não salvo nesta tela.'}
                            </p>
                            <Button type="submit" disabled={isSubmitting}>
                                <RefreshIcon aria-hidden="true" />
                                {isSubmitting ? 'Salvando...' : 'Salvar'}
                            </Button>
                        </div>
                    </form>
                </Card>
            </PageContainer>

            <DestructiveAlertDialog
                open={removeOpen}
                onOpenChange={setRemoveOpen}
                animation="key"
                title="Remover a chave de acesso?"
                subject="Chave de acesso da GOVNEX API"
                subjectDetail={integration.key_hint ?? undefined}
                description="As sincronizações passam a usar a GOVNEX API como consumidor anônimo, com limite de 60 requisições por minuto, até que uma nova chave seja salva."
                confirmLabel={removing ? 'Removendo...' : 'Remover chave'}
                submitting={removing}
                onConfirm={removeKey}
            />
        </>
    );
}

GovnexApiIntegration.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        {
            title: 'Integração GOVNEX API',
            href: '/admin/integracoes/govnex-api',
        },
    ],
};
