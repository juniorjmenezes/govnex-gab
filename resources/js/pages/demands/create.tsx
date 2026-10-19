import { Head } from '@inertiajs/react';
import { BackButton } from '@/components/common/back-button';
import { DemandForm } from '@/components/demands/demand-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { DemandOptions, OfficeLocation } from '@/types';

export default function CreateDemand({
    options,
    officeLocation,
}: {
    options: DemandOptions;
    officeLocation: OfficeLocation;
}) {
    return (
        <>
            <Head title="Nova demanda" />
            <PageContainer>
                <PageHeader
                    title="Nova demanda"
                    description="Registre a solicitação recebida. O protocolo será gerado automaticamente."
                    actions={<BackButton />}
                />
                <DemandForm options={options} officeLocation={officeLocation} />
            </PageContainer>
        </>
    );
}

CreateDemand.layout = {
    breadcrumbs: [
        { title: 'Demandas', href: '/demandas' },
        { title: 'Nova demanda', href: '/demandas/create' },
    ],
};
