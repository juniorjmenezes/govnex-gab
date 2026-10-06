import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/empty-state';
import { HeartBoldIcon } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/ui/section-card';
import type { ElectionFavorite } from '@/types/politics';

const integerFormatter = new Intl.NumberFormat('pt-BR');
const percentFormatter = new Intl.NumberFormat('pt-BR', {
    maximumFractionDigits: 1,
});

export function ElectionFavoritesCard({
    favorites,
    title = 'Favoritos',
    description = 'Marcados no Painel político',
}: {
    favorites: ElectionFavorite[];
    title?: string;
    description?: string;
}) {
    return (
        <SectionCard
            title={title}
            description={description}
            contentClassName="p-0"
        >
            {favorites.length === 0 ? (
                <EmptyState
                    size="compact"
                    icon={HeartBoldIcon}
                    title="Nenhum candidato favoritado"
                    description="Favorite um candidato no Painel político para acompanhar a apuração dele aqui."
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href="/painel-politico">
                                Ir para o Painel político
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <ul className="divide-y">
                    {favorites.map((favorite) => (
                        <li
                            key={favorite.candidato_politico_id}
                            className="flex items-center gap-3 px-4 py-2.5 text-sm"
                        >
                            <HeartBoldIcon
                                className="size-3.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="flex items-center gap-1.5 truncate font-medium">
                                    {favorite.name}
                                    {favorite.elected && (
                                        <Badge variant="success">Eleito</Badge>
                                    )}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {favorite.cargo_label}
                                    {favorite.party && ` · ${favorite.party}`}
                                    {favorite.number && ` · ${favorite.number}`}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                {favorite.found ? (
                                    <>
                                        <p className="font-medium tabular-nums">
                                            {integerFormatter.format(
                                                favorite.votes ?? 0,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            {percentFormatter.format(
                                                favorite.vote_percent ?? 0,
                                            )}
                                            %
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Ainda sem dados
                                    </p>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
