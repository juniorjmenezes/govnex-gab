import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRightIcon,
    Buildings2Icon,
    CheckCircleIcon,
} from '@solar-icons/react/outline';
import { Button } from '@/components/ui/button';
import { Surface } from '@/components/ui/surface';
import { dashboard, login } from '@/routes';

const benefits = [
    'Demandas organizadas por responsável, prazo e prioridade',
    'Histórico completo de cada atendimento',
    'Indicadores claros para decisões do gabinete',
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Gestão de demandas parlamentares" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:px-8">
                        <div className="flex items-center gap-3">
                            <span className="flex size-9 items-center justify-center rounded-sm bg-primary text-primary-foreground">
                                <Buildings2Icon
                                    className="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <p className="text-sm font-semibold">
                                    GOVNEX GAB
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    Gestão parlamentar
                                </p>
                            </div>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user ? 'Abrir painel' : 'Entrar'}
                                <ArrowRightIcon aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </header>

                <main>
                    <section className="mx-auto grid max-w-7xl gap-12 px-5 py-20 sm:px-8 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:py-28">
                        <div>
                            <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                                Atendimento parlamentar organizado
                            </p>
                            <h1 className="mt-4 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl lg:text-6xl">
                                Cada demanda acompanhada do início à solução.
                            </h1>
                            <p className="mt-6 max-w-2xl text-base leading-7 text-muted-foreground sm:text-lg">
                                Centralize solicitações de cidadãos, distribua
                                responsabilidades e acompanhe prazos sem
                                depender de planilhas, cadernos ou conversas
                                dispersas.
                            </p>
                            <div className="mt-8">
                                <Button asChild size="lg">
                                    <Link
                                        href={auth.user ? dashboard() : login()}
                                    >
                                        {auth.user
                                            ? 'Ir para o painel'
                                            : 'Acessar minha conta'}
                                        <ArrowRightIcon aria-hidden="true" />
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        <Surface className="p-6 sm:p-8">
                            <p className="text-sm font-semibold">
                                Uma rotina mais previsível
                            </p>
                            <div className="mt-6 space-y-5">
                                {benefits.map((benefit) => (
                                    <div key={benefit} className="flex gap-3">
                                        <CheckCircleIcon
                                            className="mt-0.5 size-5 shrink-0 text-primary"
                                            aria-hidden="true"
                                        />
                                        <p className="text-sm leading-6 text-muted-foreground">
                                            {benefit}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-8 border-t pt-5">
                                <p className="text-xs leading-5 text-muted-foreground">
                                    Ambiente interno e seguro. Não há cadastro
                                    público; os acessos são gerenciados pelo
                                    próprio gabinete.
                                </p>
                            </div>
                        </Surface>
                    </section>
                </main>
            </div>
        </>
    );
}
