import { Head, Link } from '@inertiajs/react';
import { BoxIcon, WidgetIcon } from '@solar-icons/react/outline';
import { EmptyState } from '@/components/feedback/empty-state';
import { PageContainer } from '@/components/layout/page-container';
import { Button } from '@/components/ui/button';
import { Surface } from '@/components/ui/surface';
import { dashboard } from '@/routes';

type Props = {
    module: {
        code: string;
        name: string;
        description: string;
    };
};

export default function ModuleDisabled({ module }: Props) {
    return (
        <>
            <Head title="Módulo indisponível" />
            <PageContainer>
                <Surface as="section">
                    <EmptyState
                        icon={BoxIcon}
                        title={`${module.name} não está habilitado`}
                        description={`Este gabinete não possui acesso a este módulo. ${module.description} Os dados existentes permanecem preservados.`}
                        action={
                            <Button asChild>
                                <Link href={dashboard()}>
                                    <WidgetIcon aria-hidden="true" />
                                    Voltar à visão geral
                                </Link>
                            </Button>
                        }
                    />
                </Surface>
            </PageContainer>
        </>
    );
}

ModuleDisabled.layout = {
    breadcrumbs: [
        {
            title: 'Módulo indisponível',
            href: '#',
        },
    ],
};
