import { router, usePage } from '@inertiajs/react';
import {
    ChatRoundUnreadIcon,
    CheckOutlineIcon,
    CheckReadIcon,
    CloseIcon,
    SmileCircleIcon,
    SquareArrowRightUpIcon,
} from '@/components/icons';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
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
        <Drawer>
            <DrawerTrigger
                render={
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
                }
            />
            <DrawerContent side="right" className="max-w-sm">
                <DrawerHeader className="shrink-0 flex-row items-center justify-between gap-2 border-b p-4">
                    <DrawerTitle>Notificações</DrawerTitle>
                    <DrawerClose
                        render={<Button variant="ghost" size="icon-sm" />}
                        aria-label="Fechar"
                    >
                        <CloseIcon aria-hidden="true" />
                    </DrawerClose>
                </DrawerHeader>
                {notifications.items.length === 0 ? (
                    <div className="px-3 py-8 text-center">
                        <SmileCircleIcon
                            className="mx-auto size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-2 text-sm font-medium">Tudo em dia</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Novos prazos e lembretes aparecerão aqui.
                        </p>
                    </div>
                ) : (
                    <div className="flex min-h-0 flex-1 flex-col divide-y overflow-y-auto">
                        {notifications.items.map((item) => (
                            <div
                                key={item.id}
                                className={cn(
                                    'flex flex-col gap-2 px-4 py-3 text-sm',
                                    !item.read_at && 'bg-accent/70',
                                )}
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto min-w-0 justify-start px-0 py-0 text-left tracking-normal normal-case hover:bg-transparent"
                                    onClick={() => markRead(item.id)}
                                >
                                    <span className="flex w-full min-w-0 items-start gap-2">
                                        {item.read_at ? (
                                            <CheckReadIcon
                                                aria-hidden="true"
                                                className="mt-0.5 size-3.5 shrink-0 text-primary"
                                            />
                                        ) : (
                                            <CheckOutlineIcon
                                                aria-hidden="true"
                                                className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                            />
                                        )}
                                        <span className="block min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">
                                                {item.title}
                                            </span>
                                            <span className="mt-0.5 block text-xs font-normal break-words whitespace-normal text-muted-foreground">
                                                {item.message}
                                            </span>
                                        </span>
                                    </span>
                                </Button>
                                {item.url && (
                                    <Button
                                        type="button"
                                        variant="outline"
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
                                        className="ml-5 w-fit gap-1 tracking-normal normal-case"
                                    >
                                        Abrir item
                                        <SquareArrowRightUpIcon
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
                {notifications.unread_count > 0 && (
                    <DrawerFooter className="shrink-0 border-t p-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="gap-1.5 text-xs tracking-normal normal-case"
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
                    </DrawerFooter>
                )}
            </DrawerContent>
        </Drawer>
    );
}
