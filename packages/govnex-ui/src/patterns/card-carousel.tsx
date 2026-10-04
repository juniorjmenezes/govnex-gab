import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';

import { Button } from '../components/button';
import { ChevronLeftIcon, ChevronRightIcon } from '../icons';
import { cn } from '../lib/utils';

/**
 * Fileira única de cartões pequenos, roláveis por gesto ou pelas setas —
 * "slider" de verdade (scroll nativo com `snap`), não paginação por clique.
 * As setas só aparecem quando dá para rolar, e cada uma some sozinha na
 * ponta em que não há mais o que rolar. Sem dependência nova: é a mesma
 * abordagem do carrossel oficial do shadcn, sem o embla-carousel, que não
 * se justifica para uma fileira de poucos itens.
 *
 * Cada filho deve trazer `shrink-0 snap-start` e a própria largura (ex.:
 * `w-56`) — o carrossel só rola e mostra as setas.
 */
export function CardCarousel({
    children,
    className,
    'aria-label': ariaLabel,
}: {
    children: ReactNode;
    className?: string;
    'aria-label'?: string;
}) {
    const trackRef = useRef<HTMLDivElement>(null);
    const [canScrollPrev, setCanScrollPrev] = useState(false);
    const [canScrollNext, setCanScrollNext] = useState(false);

    const updateScrollState = useCallback(() => {
        const el = trackRef.current;
        if (!el) return;
        setCanScrollPrev(el.scrollLeft > 4);
        setCanScrollNext(el.scrollLeft + el.clientWidth < el.scrollWidth - 4);
    }, []);

    useEffect(() => {
        const el = trackRef.current;
        if (!el) return;
        updateScrollState();
        el.addEventListener('scroll', updateScrollState, { passive: true });
        const resizeObserver = new ResizeObserver(updateScrollState);
        resizeObserver.observe(el);
        return () => {
            el.removeEventListener('scroll', updateScrollState);
            resizeObserver.disconnect();
        };
        // Filhos podem mudar de quantidade (período diferente, dados
        // carregados): reavalia se dá para rolar.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [updateScrollState, children]);

    const scrollByPage = (direction: 1 | -1) => {
        trackRef.current?.scrollBy({
            left: direction * trackRef.current.clientWidth * 0.9,
            behavior: 'smooth',
        });
    };

    const showControls = canScrollPrev || canScrollNext;

    return (
        <div className={cn('relative', className)}>
            <div
                ref={trackRef}
                role="region"
                aria-label={ariaLabel}
                className={cn(
                    'flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                    // Espaço para as setas não cobrirem o primeiro/último cartão —
                    // só quando há o que rolar, senão a fileira sobra com margem à toa.
                    showControls && 'scroll-px-10 px-10',
                )}
            >
                {children}
            </div>
            {showControls && (
                <>
                    {/* Centralizado por flex, não por `-translate-y-1/2`: o botão
                        compartilhado já usa `transform` para o efeito de "afundar" ao
                        pressionar (`active:translate-y-px`), e as duas utilidades
                        disputam a mesma variável `--tw-translate-y` — a do estado
                        `active` vence e anula a centralização, deslocando o botão do
                        ponto onde o clique acabou de ser registrado. */}
                    <div className="absolute inset-y-0 left-1 z-10 flex items-center">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            className={cn(
                                'rounded-full bg-card shadow-sm transition-opacity',
                                !canScrollPrev && 'pointer-events-none opacity-0',
                            )}
                            onClick={() => scrollByPage(-1)}
                            aria-label="Anterior"
                        >
                            <ChevronLeftIcon aria-hidden="true" />
                        </Button>
                    </div>
                    <div className="absolute inset-y-0 right-1 z-10 flex items-center">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            className={cn(
                                'rounded-full bg-card shadow-sm transition-opacity',
                                !canScrollNext && 'pointer-events-none opacity-0',
                            )}
                            onClick={() => scrollByPage(1)}
                            aria-label="Próximo"
                        >
                            <ChevronRightIcon aria-hidden="true" />
                        </Button>
                    </div>
                </>
            )}
        </div>
    );
}
