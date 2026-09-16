import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ActivityMark } from '@/components/common/activity-mark';
import { ActivityToggleButton } from '@/components/common/activity-toggle-button';
import {
    TableActionButton,
    tableButtonOutlineHoverClass,
} from '@/components/common/table-action-button';
import { EmptyState } from '@/components/feedback/empty-state';
import { FieldError } from '@/components/forms/field-error';
import {
    AddIcon,
    ChatRoundDotsIcon,
    DisketteIcon,
    HistoryIcon,
    LinkIcon,
    RefreshIcon,
    SendSquareIcon,
    UnlinkIcon,
    UsersGroupRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { checkRequiredFields } from '@/lib/required-fields';

type Option = { value: string; label: string };
type Template = {
    id: number;
    purpose: string;
    name: string;
    status: string;
    active: boolean;
    gateway_id: number | null;
    components: Array<{ type?: string; text?: string }> | null;
};
type Props = {
    offices: Array<{
        id: number;
        entidade_id: number;
        entidade_name: string | null;
        nome: string;
        status: string;
        whatsapp_enabled: boolean;
    }>;
    selectedOfficeId: number | null;
    selectedEntidade: { id: number; name: string } | null;
    connection: {
        id: number;
        gateway_account_id: number;
        type: 'PROPRIA' | 'CENTRAL';
        name: string;
        phone_last_four: string | null;
        status: string;
        active: boolean;
    } | null;
    gatewayAccounts: Array<{
        id: number;
        name: string;
        phone_last_four: string | null;
        active: boolean;
        status: string;
    }>;
    configuration: {
        mode: string;
        digest_time: string;
        purposes: string[];
        module_enabled: boolean;
    } | null;
    purposeOptions: Array<
        Option & {
            meta_name: string;
            source_module: string;
            source_module_label: string;
            available: boolean;
        }
    >;
    modeOptions: Option[];
    templates: Template[];
    contacts: Array<{
        id: number;
        kind: string;
        name: string;
        last_four: string | null;
        status: string;
        pilot: boolean;
        eligible: boolean;
    }>;
    outbox: Array<{
        id: number;
        request_id: string;
        purpose: string;
        status: string;
        last_four: string;
        attempts: number;
        error: string | null;
        created_at: string | null;
    }>;
    gateway: {
        driver: string;
        real_enabled: boolean;
        url_configured: boolean;
        request_secret_configured: boolean;
        callback_secret_configured: boolean;
        callback_url: string;
    };
};

function TemplateRow({
    template,
    entidadeId,
}: {
    template: Template;
    entidadeId: number;
}) {
    const defaultBody =
        template.components?.find((component) => component.type === 'BODY')
            ?.text ?? '';
    const [body, setBody] = useState(defaultBody);
    const busy = [
        'PENDING',
        'SUBMITTED',
        'SUBMITTING',
        'SUBMISSION_AMBIGUOUS',
    ].includes(template.status);

    return (
        <TableRow>
            <TableCell className="w-10">
                <ActivityMark
                    active={template.active}
                    activeLabel="Template ativo"
                    inactiveLabel="Template inativo"
                />
            </TableCell>
            <TableCell>
                <p className="font-normal">{template.purpose}</p>
                <p className="max-w-72 truncate text-xs text-muted-foreground">
                    {template.name}
                </p>
            </TableCell>
            <TableCell>
                <Badge
                    variant={
                        template.status === 'APPROVED' ? 'default' : 'outline'
                    }
                >
                    {template.status}
                </Badge>
            </TableCell>
            <TableCell className="min-w-80 whitespace-normal">
                <Textarea
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                    disabled={
                        template.status !== 'DRAFT' &&
                        template.gateway_id !== null
                    }
                    rows={3}
                />
            </TableCell>
            <TableCell>
                <div className="flex flex-wrap gap-2">
                    {template.gateway_id === null && (
                        <TableActionButton
                            label={`Criar template de ${template.purpose}`}
                            onClick={() =>
                                router.post('/admin/whatsapp/templates', {
                                    entidade_id: entidadeId,
                                    purpose: template.purpose,
                                    body: body || null,
                                })
                            }
                        >
                            <AddIcon aria-hidden="true" />
                        </TableActionButton>
                    )}
                    {template.status === 'DRAFT' && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                className={tableButtonOutlineHoverClass}
                                onClick={() =>
                                    router.patch(
                                        `/admin/whatsapp/templates/${template.id}`,
                                        { body },
                                    )
                                }
                            >
                                <DisketteIcon />
                                Salvar
                            </Button>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/admin/whatsapp/templates/${template.id}/submeter`,
                                    )
                                }
                            >
                                <SendSquareIcon />
                                Submeter
                            </Button>
                        </>
                    )}
                    {template.gateway_id !== null &&
                        template.status !== 'DRAFT' &&
                        !busy &&
                        !template.active && (
                            <Button
                                size="sm"
                                variant="outline"
                                className={tableButtonOutlineHoverClass}
                                onClick={() =>
                                    router.post(
                                        `/admin/whatsapp/templates/${template.id}/nova-versao`,
                                        { body: body || null },
                                    )
                                }
                            >
                                <AddIcon />
                                Nova versão
                            </Button>
                        )}
                    {template.status === 'APPROVED' && (
                        <ActivityToggleButton
                            active={template.active}
                            name={`template de ${template.purpose}`}
                            onClick={() =>
                                router.patch(
                                    `/admin/whatsapp/templates/${template.id}/ativacao`,
                                    { active: !template.active },
                                )
                            }
                        />
                    )}
                    {busy && (
                        <span className="text-xs text-muted-foreground">
                            Em análise
                        </span>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

export default function WhatsAppAdmin(props: Props) {
    const form = useForm({
        mode: props.configuration?.mode ?? 'OFF',
        digest_time: props.configuration?.digest_time ?? '08:00',
        purposes: props.configuration?.purposes ?? [],
    });
    const connectionForm = useForm({
        account_id: props.connection?.gateway_account_id.toString() ?? '',
        type: props.connection?.type ?? ('CENTRAL' as 'PROPRIA' | 'CENTRAL'),
    });
    const gatewayReady =
        props.gateway.url_configured &&
        props.gateway.request_secret_configured &&
        props.gateway.callback_secret_configured;
    const moduleEnabled = props.configuration?.module_enabled ?? false;
    const channelReady = moduleEnabled && props.connection !== null;

    return (
        <>
            <Head title="WhatsApp" />
            <PageContainer>
                <PageHeader
                    title="WhatsApp"
                    description="Configuração do canal, templates e entregas do Gateway WhatsApp F3 Sistemas."
                    actions={
                        <Button
                            variant="outline"
                            disabled={
                                props.connection === null ||
                                props.selectedEntidade === null
                            }
                            onClick={() =>
                                router.post(
                                    '/admin/whatsapp/templates/sincronizar',
                                    {
                                        entidade_id: props.selectedEntidade?.id,
                                    },
                                )
                            }
                        >
                            <RefreshIcon />
                            Sincronizar templates
                        </Button>
                    }
                />

                <Alert variant={gatewayReady ? 'success' : 'warning'}>
                    <ChatRoundDotsIcon />
                    <AlertTitle>
                        Gateway {gatewayReady ? 'configurado' : 'incompleto'}
                    </AlertTitle>
                    <AlertDescription>
                        Driver {props.gateway.driver}; envio real{' '}
                        {props.gateway.real_enabled
                            ? 'habilitado'
                            : 'desabilitado'}
                        . Callback: {props.gateway.callback_url}
                    </AlertDescription>
                </Alert>

                <Card className="gap-0 py-0">
                    <SurfaceHeader
                        actions={
                            props.selectedEntidade && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="shrink-0"
                                    onClick={() =>
                                        router.post(
                                            `/admin/whatsapp/entidades/${props.selectedEntidade?.id}/contas/consultar`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RefreshIcon />
                                    Consultar contas
                                </Button>
                            )
                        }
                    >
                        <SurfaceTitle>Conta da entidade</SurfaceTitle>
                        <SurfaceDescription>
                            {props.selectedEntidade?.name ??
                                'Selecione um gabinete para identificar a entidade.'}
                        </SurfaceDescription>
                    </SurfaceHeader>

                    {(props.connection ||
                        (props.gatewayAccounts.length > 0 &&
                            props.selectedEntidade)) && (
                        <div className="space-y-5 p-5">
                            {props.connection && (
                                <Alert>
                                    <LinkIcon />
                                    <AlertTitle>
                                        {props.connection.name}
                                        {props.connection.phone_last_four
                                            ? ` · final ${props.connection.phone_last_four}`
                                            : ''}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {props.connection.type === 'PROPRIA'
                                            ? 'Conta própria'
                                            : 'Conta central atribuída'}{' '}
                                        · {props.connection.status}
                                    </AlertDescription>
                                    <AlertAction>
                                        <Button
                                            type="button"
                                            size="icon-xs"
                                            variant="outline"
                                            aria-label="Desativar conexão"
                                            title="Desativar conexão"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        'Desativar esta conexão e bloquear novos envios da entidade?',
                                                    )
                                                ) {
                                                    router.delete(
                                                        `/admin/whatsapp/entidades/${props.selectedEntidade?.id}/conexao`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            <UnlinkIcon />
                                        </Button>
                                    </AlertAction>
                                </Alert>
                            )}

                            {props.gatewayAccounts.length > 0 &&
                                props.selectedEntidade && (
                                    <form
                                        noValidate
                                        className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,240px)_auto] md:items-end"
                                        onSubmit={(event) => {
                                            event.preventDefault();

                                            if (
                                                !checkRequiredFields(
                                                    connectionForm.data,
                                                    connectionForm,
                                                    {
                                                        account_id:
                                                            'Selecione a conta.',
                                                    },
                                                )
                                            ) {
                                                return;
                                            }

                                            connectionForm.post(
                                                `/admin/whatsapp/entidades/${props.selectedEntidade?.id}/conexao`,
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        <div className="space-y-1">
                                            <Label>Conta disponível</Label>
                                            <AppSelect
                                                value={
                                                    connectionForm.data
                                                        .account_id
                                                }
                                                onValueChange={(value) =>
                                                    connectionForm.setData(
                                                        'account_id',
                                                        value,
                                                    )
                                                }
                                                placeholder="Selecione a conta"
                                                options={props.gatewayAccounts.map(
                                                    (account) => ({
                                                        value: account.id.toString(),
                                                        disabled:
                                                            !account.active,
                                                        label: `${account.name}${account.phone_last_four ? ` · final ${account.phone_last_four}` : ''} · ${account.status}`,
                                                    }),
                                                )}
                                            />
                                            <FieldError
                                                message={
                                                    connectionForm.errors
                                                        .account_id
                                                }
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Modelo de uso</Label>
                                            <AppSelect
                                                value={connectionForm.data.type}
                                                onValueChange={(value) =>
                                                    connectionForm.setData(
                                                        'type',
                                                        value as
                                                            | 'PROPRIA'
                                                            | 'CENTRAL',
                                                    )
                                                }
                                                options={[
                                                    {
                                                        value: 'PROPRIA',
                                                        label: 'Conta própria',
                                                    },
                                                    {
                                                        value: 'CENTRAL',
                                                        label: 'Conta central atribuída',
                                                    },
                                                ]}
                                            />
                                            <FieldError
                                                message={
                                                    connectionForm.errors.type
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={connectionForm.processing}
                                        >
                                            <LinkIcon />
                                            Vincular conta
                                        </Button>
                                    </form>
                                )}
                        </div>
                    )}
                </Card>

                <Card className="gap-0 py-0">
                    <SurfaceHeader help="O modo OFF continua sendo o estado seguro padrão.">
                        <SurfaceTitle>Gabinete e operação</SurfaceTitle>
                    </SurfaceHeader>
                    <div className="space-y-4 p-5">
                        {props.selectedOfficeId &&
                            props.configuration &&
                            !moduleEnabled && (
                                <Alert variant="warning">
                                    <ChatRoundDotsIcon />
                                    <AlertTitle>
                                        WhatsApp desativado neste gabinete
                                    </AlertTitle>
                                    <AlertDescription>
                                        A configuração e o histórico foram
                                        preservados. Ative o módulo na gestão do
                                        gabinete para liberar novos envios.
                                    </AlertDescription>
                                </Alert>
                            )}
                        {props.selectedOfficeId && props.configuration ? (
                            <form
                                noValidate
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();

                                    if (
                                        !checkRequiredFields(form.data, form, {
                                            digest_time:
                                                'Informe o horário do resumo.',
                                        })
                                    ) {
                                        return;
                                    }

                                    form.put(
                                        `/admin/whatsapp/gabinetes/${props.selectedOfficeId}`,
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label>Gabinete</Label>
                                        <AppSelect
                                            value={props.selectedOfficeId?.toString()}
                                            onValueChange={(value) =>
                                                router.get('/admin/whatsapp', {
                                                    gabinete_id: value,
                                                })
                                            }
                                            placeholder="Selecione o gabinete"
                                            options={props.offices.map(
                                                (office) => ({
                                                    value: office.id.toString(),
                                                    label: `${office.nome}${office.whatsapp_enabled ? '' : ' · módulo desativado'}`,
                                                }),
                                            )}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label>Modo</Label>
                                        <AppSelect
                                            value={form.data.mode}
                                            disabled={!channelReady}
                                            onValueChange={(value) =>
                                                form.setData('mode', value)
                                            }
                                            options={props.modeOptions}
                                        />
                                        <FieldError
                                            message={form.errors.mode}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="digest-time">
                                            Resumo diário
                                        </Label>
                                        <Input
                                            id="digest-time"
                                            type="time"
                                            aria-required="true"
                                            value={form.data.digest_time}
                                            disabled={!channelReady}
                                            onChange={(event) =>
                                                form.setData(
                                                    'digest_time',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <FieldError
                                            message={form.errors.digest_time}
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    {props.purposeOptions.map((option) => (
                                        <Label
                                            key={option.value}
                                            className="items-start justify-between gap-3 rounded-lg border p-3 has-disabled:cursor-not-allowed has-disabled:opacity-60"
                                        >
                                            <span>
                                                <span className="block">
                                                    {option.label}
                                                </span>
                                                {!option.available && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Exige{' '}
                                                        {
                                                            option.source_module_label
                                                        }
                                                    </span>
                                                )}
                                            </span>
                                            <Switch
                                                checked={form.data.purposes.includes(
                                                    option.value,
                                                )}
                                                disabled={
                                                    !channelReady ||
                                                    !option.available
                                                }
                                                onCheckedChange={(checked) => {
                                                    if (
                                                        checked &&
                                                        !option.available
                                                    ) {
                                                        return;
                                                    }

                                                    form.setData(
                                                        'purposes',
                                                        checked
                                                            ? [
                                                                  ...form.data
                                                                      .purposes,
                                                                  option.value,
                                                              ]
                                                            : form.data.purposes.filter(
                                                                  (purpose) =>
                                                                      purpose !==
                                                                      option.value,
                                                              ),
                                                    );
                                                }}
                                            />
                                        </Label>
                                    ))}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing || !channelReady}
                                >
                                    <DisketteIcon />
                                    Salvar configuração
                                </Button>
                            </form>
                        ) : (
                            <AppSelect
                                className="w-auto max-w-md"
                                value={props.selectedOfficeId?.toString()}
                                onValueChange={(value) =>
                                    router.get('/admin/whatsapp', {
                                        gabinete_id: value,
                                    })
                                }
                                placeholder="Selecione o gabinete"
                                options={props.offices.map((office) => ({
                                    value: office.id.toString(),
                                    label: `${office.nome}${office.whatsapp_enabled ? '' : ' · módulo desativado'}`,
                                }))}
                            />
                        )}
                    </div>
                </Card>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Templates</SurfaceTitle>
                    </SurfaceHeader>
                    {props.templates.length === 0 || !props.selectedEntidade ? (
                        <EmptyState
                            icon={ChatRoundDotsIcon}
                            title="Nenhum template da entidade"
                            description="Vincule uma conta à entidade para administrar os templates desta conta."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <span className="sr-only">
                                            Ativação
                                        </span>
                                    </TableHead>
                                    <TableHead>Finalidade</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Texto</TableHead>
                                    <TableHead>Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {props.templates.map((template) => (
                                    <TemplateRow
                                        key={template.id}
                                        template={template}
                                        entidadeId={props.selectedEntidade!.id}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Contatos</SurfaceTitle>
                    </SurfaceHeader>
                    {props.contacts.length === 0 ? (
                        <EmptyState
                            icon={UsersGroupRoundedIcon}
                            title="Nenhum contato encontrado"
                            description="Os contatos aparecerão aqui assim que houver interações registradas pelo canal."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Contato</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Piloto</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {props.contacts.map((contact) => (
                                    <TableRow key={contact.id}>
                                        <TableCell>
                                            {contact.name}
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                final {contact.last_four}
                                            </span>
                                        </TableCell>
                                        <TableCell>{contact.kind}</TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    contact.eligible
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {contact.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                size="sm"
                                                variant={
                                                    contact.pilot
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                className={
                                                    contact.pilot
                                                        ? tableButtonOutlineHoverClass
                                                        : undefined
                                                }
                                                disabled={!contact.eligible}
                                                onClick={() =>
                                                    router.patch(
                                                        `/admin/whatsapp/contatos/${contact.id}/piloto`,
                                                        {
                                                            pilot: !contact.pilot,
                                                        },
                                                    )
                                                }
                                            >
                                                {contact.pilot
                                                    ? 'Remover'
                                                    : 'Adicionar'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Entregas recentes</SurfaceTitle>
                    </SurfaceHeader>
                    {props.outbox.length === 0 ? (
                        <EmptyState
                            icon={HistoryIcon}
                            title="Nenhuma entrega encontrada"
                            description="As mensagens enviadas pelo gateway aparecerão aqui."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Finalidade</TableHead>
                                    <TableHead>Contato</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Tentativas</TableHead>
                                    <TableHead>Diagnóstico</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {props.outbox.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>{item.purpose}</TableCell>
                                        <TableCell>
                                            final {item.last_four}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {item.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{item.attempts}</TableCell>
                                        <TableCell className="max-w-80 truncate">
                                            {item.error ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>
            </PageContainer>
        </>
    );
}

WhatsAppAdmin.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'WhatsApp', href: '/admin/whatsapp' },
    ],
};
