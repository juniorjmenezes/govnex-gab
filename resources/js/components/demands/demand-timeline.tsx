import {
    AddIcon,
    CalendarMarkIcon,
    ChatSquareIcon,
    ChatSquareArrowIcon,
    ChecklistIcon,
    ClipboardCheckIcon,
    ClockCircleIcon,
    LockKeyholeIcon,
    PaperclipIcon,
    Pen2Icon,
    RestartIcon,
    UserCheckRoundedIcon,
} from '@solar-icons/react/outline';
import { AttachmentList } from '@/components/demands/attachment-list';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { DemandEvent, DemandEventType } from '@/types';

const formatDateTime = (date: string) => {
    const value = new Date(date);
    const day = new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(value);
    const time = new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(value);

    return `${day}, ${time}`;
};

const formatDate = (date: string) =>
    new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(`${date.slice(0, 10)}T12:00:00`));

const icons: Record<DemandEventType, typeof AddIcon> = {
    demanda_criada: AddIcon,
    atualizacao: ChatSquareIcon,
    encaminhamento: ChatSquareArrowIcon,
    retorno_recebido: ClipboardCheckIcon,
    anexo_adicionado: PaperclipIcon,
    anexo_removido: PaperclipIcon,
    responsavel_alterado: UserCheckRoundedIcon,
    prioridade_alterada: Pen2Icon,
    prazo_alterado: CalendarMarkIcon,
    cidadao_alterado: Pen2Icon,
    status_alterado: RestartIcon,
    proxima_acao_definida: ClockCircleIcon,
    proxima_acao_concluida: ChecklistIcon,
    demanda_resolvida: ChecklistIcon,
    demanda_reaberta: RestartIcon,
    demanda_encerrada: LockKeyholeIcon,
    demanda_atualizada: Pen2Icon,
};

/** Cor do selo do ícone — agrupada pelo "peso" do evento, não um tom por tipo. */
const iconColors: Record<DemandEventType, string> = {
    demanda_criada:
        'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400',
    atualizacao:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    encaminhamento:
        'bg-indigo-100 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400',
    retorno_recebido:
        'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400',
    anexo_adicionado:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    anexo_removido:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    responsavel_alterado:
        'bg-violet-100 text-violet-600 dark:bg-violet-950 dark:text-violet-400',
    prioridade_alterada:
        'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400',
    prazo_alterado:
        'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400',
    cidadao_alterado:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    status_alterado:
        'bg-sky-100 text-sky-600 dark:bg-sky-950 dark:text-sky-400',
    proxima_acao_definida:
        'bg-sky-100 text-sky-600 dark:bg-sky-950 dark:text-sky-400',
    proxima_acao_concluida:
        'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400',
    demanda_resolvida:
        'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400',
    demanda_reaberta:
        'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400',
    demanda_encerrada:
        'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    demanda_atualizada:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const titles: Record<DemandEventType, string> = {
    demanda_criada: 'Demanda criada',
    atualizacao: 'Atualização',
    encaminhamento: 'Encaminhamento',
    retorno_recebido: 'Retorno recebido',
    anexo_adicionado: 'Arquivo anexado',
    anexo_removido: 'Arquivo removido',
    responsavel_alterado: 'Responsável alterado',
    prioridade_alterada: 'Prioridade alterada',
    prazo_alterado: 'Prazo alterado',
    cidadao_alterado: 'Solicitante alterado',
    status_alterado: 'Status alterado',
    proxima_acao_definida: 'Próxima ação definida',
    proxima_acao_concluida: 'Próxima ação concluída',
    demanda_resolvida: 'Demanda resolvida',
    demanda_reaberta: 'Demanda reaberta',
    demanda_encerrada: 'Demanda encerrada',
    demanda_atualizada: 'Dados atualizados',
};

/** Data prevista, gravada em `dados.data` quando a próxima ação é definida. */
const nextActionDate = (event: DemandEvent) => {
    const value = event.dados?.data;

    return typeof value === 'string' ? value : null;
};

/**
 * Título da linha — para encaminhamento/retorno, já embute o destino em vez
 * de repetir um rótulo genérico e deixar o destino escondido lá embaixo,
 * dentro de um bloco de detalhes. Para a próxima ação, quando há data
 * prevista, embute descrição e prazo juntos ("Visitar Central de Regulação
 * - até 05/09/2026") em vez do rótulo genérico "Próxima ação definida" —
 * "até" deixa claro que é o prazo para executar, não a data do evento.
 */
const eventTitle = (event: DemandEvent) => {
    if (event.tipo === 'encaminhamento') {
        return `Encaminhado para ${event.destino ?? 'destino não informado'}`;
    }

    if (event.tipo === 'retorno_recebido' && event.retorno_de) {
        return `Retorno de ${event.retorno_de.destino ?? 'destino não informado'}`;
    }

    if (event.tipo === 'proxima_acao_definida' && event.descricao) {
        const date = nextActionDate(event);

        return date
            ? `${event.descricao} - até ${formatDate(date)}`
            : titles[event.tipo];
    }

    return titles[event.tipo];
};

/** Setor e prazo do encaminhamento, condensados numa única linha. */
const eventMeta = (event: DemandEvent) => {
    if (event.tipo !== 'encaminhamento') {
        return null;
    }

    const parts = [
        event.setor,
        event.prazo_esperado
            ? `Prazo: ${formatDate(event.prazo_esperado)}`
            : null,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(' · ') : null;
};

/**
 * Timeline de verdade: o ícone fica fora do card, numa coluna própria,
 * ligado ao próximo por uma linha vertical — o card ao lado só carrega o
 * conteúdo (título, meta, badge, detalhes). A linha é posicionada de forma
 * absoluta (top-11 até bottom-0 do próprio `<li>`) em vez de esticada via
 * flex: depender do cross-size do flex para cobrir a margem do card
 * seguinte é frágil e deixava a linha cortada antes de alcançar o próximo
 * ícone.
 */
export function DemandTimeline({
    demandId,
    events,
}: {
    demandId: number;
    events: DemandEvent[];
}) {
    if (events.length === 0) {
        return (
            <p className="p-5 text-sm text-muted-foreground">
                Nenhum evento registrado ainda.
            </p>
        );
    }

    return (
        <ol className="p-4">
            {events.map((event, index) => {
                const Icon = icons[event.tipo];
                const meta = eventMeta(event);
                const isReferral = event.tipo === 'encaminhamento';
                const isLast = index === events.length - 1;
                // Já embutida no título junto com a data — não repete abaixo.
                const descriptionInTitle =
                    event.tipo === 'proxima_acao_definida' &&
                    nextActionDate(event) !== null;
                const hasExtra = Boolean(
                    meta ||
                    (event.descricao && !descriptionInTitle) ||
                    (event.anexos && event.anexos.length > 0),
                );

                return (
                    <li
                        key={event.id}
                        className={cn('relative flex gap-4', !isLast && 'pb-3')}
                    >
                        {!isLast && (
                            <div className="absolute top-11 bottom-0 left-[1.375rem] w-px bg-border" />
                        )}
                        <span
                            className={cn(
                                'relative z-10 flex size-11 shrink-0 items-center justify-center rounded-xl',
                                iconColors[event.tipo],
                            )}
                        >
                            <Icon className="size-5" />
                        </span>

                        <div className="min-w-0 flex-1 rounded-2xl border bg-muted/40 p-4">
                            <div className="flex items-center gap-2">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-xs font-medium uppercase">
                                        {eventTitle(event)}
                                    </p>
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {event.usuario?.name ?? 'Sistema'} ·{' '}
                                        {formatDateTime(event.created_at)}
                                    </p>
                                </div>
                                {isReferral && (
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'shrink-0 rounded-full',
                                            event.retorno_recebido_em
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
                                        )}
                                    >
                                        {event.retorno_recebido_em
                                            ? 'Retorno recebido'
                                            : 'Aguardando retorno'}
                                    </Badge>
                                )}
                            </div>

                            {hasExtra && (
                                <div className="mt-3 space-y-2 border-t pt-3">
                                    {meta && (
                                        <p className="text-xs text-muted-foreground">
                                            {meta}
                                        </p>
                                    )}
                                    {event.descricao && !descriptionInTitle && (
                                        <p className="text-sm whitespace-pre-wrap">
                                            {event.descricao}
                                        </p>
                                    )}
                                    {event.anexos &&
                                        event.anexos.length > 0 && (
                                            <AttachmentList
                                                demandId={demandId}
                                                attachments={event.anexos}
                                            />
                                        )}
                                </div>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
