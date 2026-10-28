import { useState } from 'react';
import { CheckOutlineIcon, CloseIcon, MagnifierIcon } from '@/components/icons';
import {
    PARTY_LIST_LIMIT,
    PartyRow,
    SeatHemicycle,
    isNationwideSeats,
} from '@/components/politics/seat-hemicycle';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Input } from '@/components/ui/input';
import type { ElectionResult } from '@/types/politics';

const numberFormatter = new Intl.NumberFormat('pt-BR');

/**
 * Cabeçalho, corpo e rodapé de uma casa — isolados num componente à parte e
 * remontados por `key` (a chave é o cargo) sempre que `SeatCompositionDrawer`
 * troca de casa, pra "ver todos os partidos" e a busca voltarem ao estado
 * inicial sozinhos, sem precisar de um efeito pra resetá-los.
 */
function SeatCompositionDrawerContent({
    result,
    uf,
}: {
    result: ElectionResult;
    uf?: string;
}) {
    const [showAll, setShowAll] = useState(false);
    const [query, setQuery] = useState('');

    const seats = result.seats ?? [];
    const electedSeats = seats.reduce((sum, party) => sum + party.seats, 0);
    const nationwide = isNationwideSeats(seats);
    const hasMoreParties = seats.length > PARTY_LIST_LIMIT;
    const term = query.trim().toLowerCase();
    const filteredParties =
        term === ''
            ? seats
            : seats.filter((item) => item.party.toLowerCase().includes(term));

    return (
        <>
            <DrawerHeader className="shrink-0 flex-row items-start justify-between gap-3 border-b p-4">
                <div className="min-w-0">
                    <DrawerTitle>
                        {showAll ? 'Partidos' : 'Composição'} · {result.label}
                    </DrawerTitle>
                    <DrawerDescription className="mt-0.5">
                        {numberFormatter.format(electedSeats)} cadeiras eleitas
                        · {numberFormatter.format(seats.length)} partidos
                    </DrawerDescription>
                </div>
                <DrawerClose
                    render={<Button variant="ghost" size="icon-sm" />}
                    aria-label="Fechar"
                >
                    <CloseIcon aria-hidden="true" />
                </DrawerClose>
            </DrawerHeader>

            <div className="min-h-0 flex-1 overflow-y-auto p-4">
                {showAll ? (
                    <div className="flex flex-col gap-4">
                        <div className="relative">
                            <MagnifierIcon
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Buscar por sigla do partido"
                                aria-label="Buscar partidos"
                                className="pl-9"
                            />
                        </div>
                        {filteredParties.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                Nenhum partido encontrado.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {filteredParties.map((item) => (
                                    <PartyRow
                                        key={item.party}
                                        item={item}
                                        nationwide={nationwide}
                                        uf={uf}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                ) : (
                    <SeatHemicycle
                        parties={seats}
                        chamberTotal={result.chamber_total}
                        uf={uf}
                        othersLabel={
                            result.cargo === 'senador'
                                ? 'Senadores em exercício'
                                : undefined
                        }
                    />
                )}
            </div>

            {hasMoreParties && (
                <DrawerFooter className="flex-row justify-end border-t p-4">
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        onClick={() => setShowAll((value) => !value)}
                    >
                        <CheckOutlineIcon aria-hidden="true" />
                        {showAll
                            ? 'Ver resumo'
                            : `Ver todos os partidos (${seats.length})`}
                    </Button>
                </DrawerFooter>
            )}
        </>
    );
}

/**
 * Composição de uma casa (Senado, Câmara, Assembleia ou Câmara Municipal) num
 * painel lateral, aberto a partir do botão da casa em `apuracao.tsx` — em vez
 * de todas as casas lado a lado o tempo todo, só uma por vez, sob pedido.
 *
 * "Ver todos os partidos" troca o corpo do próprio drawer para a lista
 * completa com busca — não abre outro painel por cima; o rodapé é o único
 * lugar fixo pro botão, tanto pra entrar quanto pra voltar à visão compacta.
 * `result` fica `null` enquanto o painel fecha (a troca de casa some antes da
 * animação terminar).
 */
export function SeatCompositionDrawer({
    result,
    open,
    onOpenChange,
    uf,
    municipal,
}: {
    result: ElectionResult | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    uf?: string;
    /** Câmara Municipal: sem cadeiras de outra UF pra destacar. */
    municipal: boolean;
}) {
    return (
        <Drawer open={open} onOpenChange={onOpenChange} swipeDirection="right">
            <DrawerContent side="right">
                {result ? (
                    <SeatCompositionDrawerContent
                        key={result.cargo}
                        result={result}
                        uf={municipal ? undefined : uf}
                    />
                ) : (
                    <DrawerHeader className="shrink-0 border-b p-4">
                        <DrawerTitle>Composição</DrawerTitle>
                    </DrawerHeader>
                )}
            </DrawerContent>
        </Drawer>
    );
}
