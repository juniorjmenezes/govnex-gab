import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AttachmentField } from '@/components/forms/attachment-field';
import {
    CheckCircleIcon,
    CloseCircleIcon,
    CloudUploadIcon,
    DangerTriangleIcon,
    DatabaseIcon,
    ServerIcon,
    SsdRoundIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Surface } from '@/components/ui/surface';
import { cn } from '@/lib/utils';

type ExtensionCheck = { name: string; description: string; loaded: boolean };
type IniCheck = { value: string; bytesLimit: number | null };
type WritablePathCheck = { label: string; writable: boolean };
type DatabaseCheck = {
    connected: boolean;
    driver: string;
    error: string | null;
};
type QueueCheck = { driver: string; connected: boolean; error: string | null };
type UploadTestResult = {
    name: string;
    size_bytes: number;
    mime_type: string | null;
    tested_at: string;
};

type Props = {
    php: { version: string; sapi: string };
    extensions: ExtensionCheck[];
    ini: Record<string, IniCheck>;
    writablePaths: WritablePathCheck[];
    database: DatabaseCheck;
    queue: QueueCheck;
    diskFreeBytes: number | null;
    app: { env: string; debug: boolean; url: string; timezone: string };
    uploadTest: UploadTestResult | null;
};

const iniLabels: Record<string, string> = {
    upload_max_filesize: 'Tamanho máximo por arquivo',
    post_max_size: 'Tamanho máximo da requisição',
    memory_limit: 'Memória por processo',
    max_execution_time: 'Tempo máximo de execução',
    max_input_time: 'Tempo máximo de recebimento do upload',
};

const formatBytes = (bytes: number) => {
    if (bytes >= 1024 * 1024 * 1024) {
        return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
    }

    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
};

export default function SystemCheck({
    php,
    extensions,
    ini,
    writablePaths,
    database,
    queue,
    diskFreeBytes,
    app,
    uploadTest,
}: Props) {
    const missingExtensions = extensions.filter((item) => !item.loaded);
    const unwritablePaths = writablePaths.filter((item) => !item.writable);
    const criticalIssues =
        missingExtensions.length +
        unwritablePaths.length +
        (database.connected ? 0 : 1);

    return (
        <>
            <Head title="Diagnóstico do sistema" />
            <PageContainer>
                <PageHeader
                    title="Diagnóstico do sistema"
                    description="Recursos mínimos para a aplicação funcionar: extensões do PHP, limites de upload/execução em vigor agora mesmo, banco, fila e um teste real de envio de arquivo."
                />

                {criticalIssues === 0 ? (
                    <div className="flex items-center gap-2 rounded-2xl bg-emerald-500/10 p-4 text-sm text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-400">
                        <CheckCircleIcon className="size-5 shrink-0" />
                        Tudo que verificamos está dentro do esperado.
                    </div>
                ) : (
                    <div className="flex items-center gap-2 rounded-2xl bg-destructive/10 p-4 text-sm text-destructive ring-1 ring-destructive/20">
                        <DangerTriangleIcon className="size-5 shrink-0" />
                        {criticalIssues === 1
                            ? '1 item precisa de atenção.'
                            : `${criticalIssues} itens precisam de atenção.`}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Ambiente
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                PHP {php.version} · SAPI {php.sapi}
                            </p>
                        </div>
                        <dl className="grid grid-cols-2 gap-4 p-4 text-sm">
                            <Info label="Ambiente (APP_ENV)" value={app.env} />
                            <Info
                                label="Depuração (APP_DEBUG)"
                                value={app.debug ? 'Ativada' : 'Desativada'}
                                danger={app.debug && app.env === 'production'}
                            />
                            <Info label="URL" value={app.url} />
                            <Info label="Fuso horário" value={app.timezone} />
                            <Info
                                label="Espaço livre em disco"
                                value={
                                    diskFreeBytes !== null
                                        ? formatBytes(diskFreeBytes)
                                        : 'Não foi possível calcular'
                                }
                            />
                        </dl>
                    </Surface>

                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Banco de dados e fila
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Conectividade real, testada agora.
                            </p>
                        </div>
                        <ul className="divide-y">
                            <li className="flex items-center justify-between gap-3 p-4 text-sm">
                                <span className="flex items-center gap-2">
                                    <DatabaseIcon className="size-4 text-muted-foreground" />
                                    Banco ({database.driver})
                                </span>
                                <StatusPill ok={database.connected} />
                            </li>
                            <li className="flex items-center justify-between gap-3 p-4 text-sm">
                                <span className="flex items-center gap-2">
                                    <ServerIcon className="size-4 text-muted-foreground" />
                                    Fila ({queue.driver})
                                </span>
                                <StatusPill ok={queue.connected} />
                            </li>
                        </ul>
                        {(!database.connected || !queue.connected) && (
                            <div className="space-y-1 border-t bg-muted/20 p-4 text-xs text-muted-foreground">
                                {!database.connected && database.error && (
                                    <p>Banco: {database.error}</p>
                                )}
                                {!queue.connected && queue.error && (
                                    <p>Fila: {queue.error}</p>
                                )}
                            </div>
                        )}
                    </Surface>
                </div>

                <Surface as="section" className="overflow-hidden">
                    <div className="border-b p-4">
                        <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                            Limites em vigor agora
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Valores lidos no exato processo que atendeu esta
                            página — se algo aqui não bater com o que você
                            configurou, o processo que está de pé (ex.:{' '}
                            <code>php artisan serve</code>) ainda não foi
                            reiniciado.
                        </p>
                    </div>
                    <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        {Object.entries(ini).map(([directive, check]) => (
                            <div
                                key={directive}
                                className="rounded-xl border p-3"
                            >
                                <p className="text-xs text-muted-foreground">
                                    {iniLabels[directive] ?? directive}
                                </p>
                                <p className="mt-1 font-mono text-sm font-medium">
                                    {check.value || '—'}
                                </p>
                            </div>
                        ))}
                    </div>
                </Surface>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Extensões do PHP
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Usadas diretamente pela aplicação.
                            </p>
                        </div>
                        <ul className="divide-y">
                            {extensions.map((extension) => (
                                <li
                                    key={extension.name}
                                    className="flex items-center justify-between gap-3 p-4 text-sm"
                                >
                                    <div className="min-w-0">
                                        <p className="font-mono">
                                            {extension.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {extension.description}
                                        </p>
                                    </div>
                                    <StatusPill ok={extension.loaded} />
                                </li>
                            ))}
                        </ul>
                    </Surface>

                    <Surface as="section" className="overflow-hidden">
                        <div className="border-b p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                                Diretórios graváveis
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Precisam de permissão de escrita para logs,
                                cache, sessões e anexos.
                            </p>
                        </div>
                        <ul className="divide-y">
                            {writablePaths.map((path) => (
                                <li
                                    key={path.label}
                                    className="flex items-center justify-between gap-3 p-4 text-sm"
                                >
                                    <span className="flex items-center gap-2 font-mono">
                                        <SsdRoundIcon className="size-4 text-muted-foreground" />
                                        {path.label}
                                    </span>
                                    <StatusPill ok={path.writable} />
                                </li>
                            ))}
                        </ul>
                    </Surface>
                </div>

                <UploadLimitTestCard
                    uploadMaxFilesize={ini.upload_max_filesize?.value ?? '—'}
                    postMaxSize={ini.post_max_size?.value ?? '—'}
                    lastResult={uploadTest}
                />
            </PageContainer>
        </>
    );
}

function Info({
    label,
    value,
    danger = false,
}: {
    label: string;
    value: string;
    danger?: boolean;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'mt-0.5 truncate font-medium',
                    danger && 'text-destructive',
                )}
                title={value}
            >
                {value}
            </dd>
        </div>
    );
}

function StatusPill({ ok }: { ok: boolean }) {
    return (
        <Badge variant={ok ? 'default' : 'destructive'}>
            {ok ? (
                <CheckCircleIcon className="size-3.5" aria-hidden="true" />
            ) : (
                <CloseCircleIcon className="size-3.5" aria-hidden="true" />
            )}
            {ok ? 'OK' : 'Faltando'}
        </Badge>
    );
}

function UploadLimitTestCard({
    uploadMaxFilesize,
    postMaxSize,
    lastResult,
}: {
    uploadMaxFilesize: string;
    postMaxSize: string;
    lastResult: UploadTestResult | null;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [progress, setProgress] = useState<number | null>(null);
    const [elapsedMs, setElapsedMs] = useState<number | null>(null);
    const [error, setError] = useState('');
    const file = files[0] ?? null;
    const uploading = progress !== null;

    const submit = () => {
        if (file === null) {
            setError('Selecione um arquivo antes de enviar.');

            return;
        }

        setError('');
        setElapsedMs(null);
        const startedAt = Date.now();
        const data = new FormData();
        data.append('arquivo', file);

        setProgress(0);
        router.post('/admin/sistema/teste-upload', data, {
            forceFormData: true,
            preserveScroll: true,
            onProgress: (event) => setProgress(event?.percentage ?? 0),
            onError: (errors) => {
                setError(
                    Object.values(errors)[0] ??
                        'Não foi possível enviar o arquivo de teste.',
                );
                setProgress(null);
            },
            onSuccess: () => {
                setElapsedMs(Date.now() - startedAt);
                setFiles([]);
                setProgress(null);
            },
            onFinish: () => setProgress(null),
        });
    };

    return (
        <Surface as="section" className="overflow-hidden">
            <div className="border-b p-4">
                <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                    Teste real de upload
                </h2>
                <p className="text-xs text-muted-foreground">
                    Envie qualquer arquivo (não precisa ser um ZIP do TSE) para
                    confirmar, na prática, até que tamanho o ambiente aceita
                    agora. Limite atual: {uploadMaxFilesize} por arquivo,{' '}
                    {postMaxSize} por requisição.
                </p>
            </div>
            <div className="flex flex-col gap-3 p-4">
                <AttachmentField
                    files={files}
                    onFilesChange={setFiles}
                    maxFiles={1}
                    maxSizeMb={100 * 1024}
                    disabled={uploading}
                    dropzoneLabel="Clique ou arraste qualquer arquivo aqui"
                    selectLabel="Selecionar arquivo"
                    error={error || undefined}
                    trailingAction={
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={uploading}
                            onClick={submit}
                            className="flex-1 rounded-none first:rounded-l-md last:rounded-r-md focus-visible:z-10"
                        >
                            <CloudUploadIcon />
                            {uploading
                                ? `Enviando… ${progress ?? 0}%`
                                : 'Testar upload'}
                        </Button>
                    }
                />
            </div>
            {lastResult && (
                <div className="flex items-start gap-3 border-t bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-400">
                    <CheckCircleIcon className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p className="font-medium">
                            {lastResult.name} —{' '}
                            {formatBytes(lastResult.size_bytes)} chegaram ao
                            servidor
                            {elapsedMs !== null &&
                                ` em ${(elapsedMs / 1000).toFixed(1)}s`}
                            .
                        </p>
                        <p className="text-xs opacity-80">
                            {lastResult.mime_type ?? 'Tipo não identificado'}
                        </p>
                    </div>
                </div>
            )}
        </Surface>
    );
}

SystemCheck.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        { title: 'Diagnóstico do sistema', href: '/admin/sistema' },
    ],
};
