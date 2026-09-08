import { Head } from '@inertiajs/react';
import { DemandForm } from '@/components/demands/demand-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { Demand, DemandOptions } from '@/types';

export default function EditDemand({
    demand,
    options,
}: {
    demand: Demand;
    options: DemandOptions;
}) {
    return (
        <>
            <Head title={`Editar ${demand.protocolo}`} />
            <PageContainer>
                <PageHeader
                    title="Editar demanda"
                    description="Atualize a solicitação, o responsável, o prazo e a localização."
                />
                <DemandForm demand={demand} options={options} />
            </PageContainer>
        </>
    );
}

EditDemand.layout = (page: { demand: Demand }) => ({
    breadcrumbs: [
        { title: 'Demandas', href: '/demandas' },
        {
            title: page.demand.protocolo,
            href: `/demandas/${page.demand.id}`,
        },
        { title: 'Editar', href: '#' },
    ],
});
