import { Head } from '@inertiajs/react';
import { CitizenForm } from '@/components/citizens/citizen-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { Citizen, Neighborhood, OfficeLocation } from '@/types';
export default function EditCitizen({
    citizen,
    neighborhoods,
    officeLocation,
    whatsappConsentText,
}: {
    citizen: Citizen;
    neighborhoods: Neighborhood[];
    officeLocation: OfficeLocation;
    whatsappConsentText: string;
}) {
    return (
        <>
            <Head title={`Editar ${citizen.nome}`} />
            <PageContainer>
                <PageHeader
                    title="Editar cadastro"
                    description={`Atualize os dados de ${citizen.nome}.`}
                />
                <CitizenForm
                    citizen={citizen}
                    neighborhoods={neighborhoods}
                    officeLocation={officeLocation}
                    whatsappConsentText={whatsappConsentText}
                />
            </PageContainer>
        </>
    );
}

EditCitizen.layout = (page: { citizen: Citizen }) => ({
    breadcrumbs: [
        { title: 'Cidadãos', href: '/cidadaos' },
        {
            title: page.citizen.nome,
            href: `/cidadaos/${page.citizen.id}`,
        },
        { title: 'Editar', href: '#' },
    ],
});
