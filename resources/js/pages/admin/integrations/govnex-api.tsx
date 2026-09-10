import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { FieldError } from '@/components/forms/field-error';
import {
    CheckCircleIcon,
    DangerCircleIcon,
    KeyIcon,
    PlugCircleIcon,
    RefreshIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface } from '@/components/ui/surface';

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
        router.put(
            '/admin/integracoes/govnex-api',
            { url: integration.url, chave: '', remover_chave: true },
            { preserveScroll: true },
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
                    <Surface className="p-4">
                        <p className="text-sm text-muted-foreground">
                            Nenhuma configuração salva ainda — os valores abaixo
                            vêm das variáveis <code>GOVNEX_API_URL</code> e{' '}
                            <code>GOVNEX_API_KEY</code> do <code>.env</code>.
                            Salvar aqui passa a valer sobre elas.
                        </p>
                    </Surface>
                )}

                {integration.verified_at && (
                    <Surface className="p-4">
                        <div className="flex items-start gap-3">
                            {ok ? (
                                <CheckCircleIcon
                                    className="mt-0.5 size-5 shrink-0 text-emerald-700 dark:text-emerald-400"
                                    aria-hidden="true"
                                />
                            ) : (
                                <DangerCircleIcon
                                    className="mt-0.5 size-5 shrink-0 text-destructive"
                                    aria-hidden="true"
                                />
                            )}
                            <div>
                                <p className="text-sm font-medium">
                                    {integration.verified_detail}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Última verificação em{' '}
                                    {dateTime.format(
                                        new Date(integration.verified_at),
                                    )}
                                </p>
                            </div>
                        </div>
                    </Surface>
                )}

                <Surface as="section" className="p-5">
                    <form
                        className="grid max-w-2xl gap-5"
                        onSubmit={handleSubmit(submit)}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="url">URL base da GOVNEX API</Label>
                            <Input
                                id="url"
                                type="url"
                                placeholder="http://127.0.0.1:8010/api/v1"
                                {...register('url')}
                            />
                            <FieldError message={errors.url?.message} />
                            <p className="text-xs text-muted-foreground">
                                Inclua o caminho até a versão da API, terminando
                                em <code>/api/v1</code>.
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="chave">Chave de acesso</Label>
                            <Input
                                id="chave"
                                type="password"
                                autoComplete="off"
                                placeholder={
                                    integration.has_key
                                        ? 'Deixe em branco para manter a chave atual'
                                        : 'Cole a chave gerada na GOVNEX API'
                                }
                                {...register('chave')}
                            />
                            <FieldError message={errors.chave?.message} />
                            {integration.has_key ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <KeyIcon
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Chave configurada
                                        {integration.key_hint && (
                                            <code>{integration.key_hint}</code>
                                        )}
                                        . O valor não é exibido de volta.
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={removeKey}
                                    >
                                        Remover chave
                                    </Button>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Sem chave a API responde assim mesmo, com o
                                    limite do consumidor anônimo: 60 requisições
                                    por minuto e per_page de até 100. Gere uma
                                    em Chaves de API, no painel da GOVNEX API.
                                </p>
                            )}
                        </div>

                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={isSubmitting}>
                                <RefreshIcon aria-hidden="true" />
                                {isSubmitting ? 'Salvando...' : 'Salvar'}
                            </Button>
                            {integration.updated_at && (
                                <p className="text-xs text-muted-foreground">
                                    Atualizado em{' '}
                                    {dateTime.format(
                                        new Date(integration.updated_at),
                                    )}
                                    {integration.updated_by
                                        ? ` por ${integration.updated_by}`
                                        : ''}
                                </p>
                            )}
                        </div>
                    </form>
                </Surface>
            </PageContainer>
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
