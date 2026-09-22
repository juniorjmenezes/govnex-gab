import { router } from '@inertiajs/react';
import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { DangerTriangleIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/feedback/empty-state';

type Props = { children: ReactNode };
type State = { error: Error | null };

/**
 * Sem isso, qualquer exceção não tratada durante a renderização (ex.: um
 * plugin de terceiros como o Leaflet falhando) derruba a árvore React
 * inteira e deixa a tela em branco, sem nenhum aviso — nem para o usuário,
 * nem no log do servidor, já que o erro nunca sai do navegador.
 *
 * Reseta sozinho quando o usuário navega para outra página via Inertia, para
 * não prender quem só queria sair da tela quebrada.
 */
export class ErrorBoundary extends Component<Props, State> {
    state: State = { error: null };
    private unlisten?: () => void;

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error('Erro não tratado na interface:', error, info);
    }

    componentDidMount() {
        this.unlisten = router.on('navigate', () => {
            if (this.state.error) {
                this.setState({ error: null });
            }
        });
    }

    componentWillUnmount() {
        this.unlisten?.();
    }

    render() {
        if (this.state.error) {
            return (
                <div className="grid min-h-svh place-items-center p-6">
                    <EmptyState
                        icon={DangerTriangleIcon}
                        title="Algo deu errado"
                        description="A tela encontrou um erro inesperado e não pôde continuar. Recarregar a página costuma resolver."
                        action={
                            <div className="flex justify-center gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => (window.location.href = '/')}
                                >
                                    Ir para o início
                                </Button>
                                <Button
                                    onClick={() => window.location.reload()}
                                >
                                    Recarregar página
                                </Button>
                            </div>
                        }
                    />
                </div>
            );
        }

        return this.props.children;
    }
}
