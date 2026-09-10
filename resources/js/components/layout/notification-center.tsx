import { router, usePage } from '@inertiajs/react';
import { ChatRoundUnreadIcon, CheckReadIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { cn } from '@/lib/utils';

export function NotificationCenter() {
    const { notifications } = usePage().props;
    const tenantUrl = useTenantUrl();
    const showCount = notifications.unread_count > 0;

    const markRead = (id: string) => {
        router.patch(
            tenantUrl(`/notificacoes/${id}/ler`),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="gap-1.5 px-2.5 focus-visible:border-transparent focus-visible:ring-0"
                    aria-label={`Abrir central de notificações. ${notifications.unread_count} não lidas.`}
                >
                    <ChatRoundUnreadIcon
                        className="size-4"
                        aria-hidden="true"
                    />
                    {showCount && (
                        <span
                            aria-hidden="true"
                            className="text-xs font-medium text-muted-foreground tabular-nums"
                        >
                            {notifications.unread_count}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-[min(22rem,calc(100vw-2rem))]"
            >
                <div className="flex items-center justify-between px-2">
                    <DropdownMenuLabel>Notificações</DropdownMenuLabel>
                    {notifications.unread_count > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 text-xs tracking-normal normal-case"
                            onClick={() =>
                                router.patch(
                                    tenantUrl('/notificacoes/ler-todas'),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <CheckReadIcon className="size-3.5" />
                            Ler todas
                        </Button>
                    )}
                </div>
                <DropdownMenuSeparator />
                {notifications.items.length === 0 ? (
                    <div className="px-3 py-8 text-center">
                        <p className="text-sm font-medium">Tudo em dia</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Novos prazos e lembretes aparecerão aqui.
                        </p>
                    </div>
                ) : (
                    <div className="max-h-96 overflow-y-auto p-1">
                        {notifications.items.map((item) => (
                            <div
                                key={item.id}
                                className={cn(
                                    'rounded-md px-3 py-2.5 text-sm',
                                    !item.read_at && 'bg-accent/70',
                                )}
                            >
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-auto min-w-0 flex-1 justify-start px-0 py-0 text-left tracking-normal normal-case hover:bg-transparent"
                                        onClick={() => markRead(item.id)}
                                    >
                                        <span className="block w-full min-w-0">
                                            <span className="block truncate text-sm font-medium">
                                                {item.title}
                                            </span>
                                            <span className="mt-0.5 block text-xs font-normal break-words whitespace-normal text-muted-foreground">
                                                {item.message}
                                            </span>
                                        </span>
                                    </Button>
                                    {!item.read_at && (
                                        <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" />
                                    )}
                                </div>
                                {item.url && (
                                    <Button
                                        type="button"
                                        variant="link"
                                        size="xs"
                                        onClick={() =>
                                            router.patch(
                                                tenantUrl(
                                                    `/notificacoes/${item.id}/ler`,
                                                ),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        router.visit(item.url!),
                                                },
                                            )
                                        }
                                        className="mt-2 h-auto px-0 tracking-normal normal-case"
                                    >
                                        Abrir item
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
