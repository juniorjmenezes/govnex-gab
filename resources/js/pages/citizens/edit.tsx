import { Head } from '@inertiajs/react';
import { CitizenForm } from '@/components/citizens/citizen-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Card } from '@/components/ui/card';
import type { Citizen, Neighborhood } from '@/types';
export default function EditCitizen({
    citizen,
    neighborhoods,
    whatsappConsentText,
}: {
    citizen: Citizen;
    neighborhoods: Neighborhood[];
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
                <Card className="p-5">
                    <CitizenForm
                        citizen={citizen}
                        neighborhoods={neighborhoods}
                        whatsappConsentText={whatsappConsentText}
                    />
                </Card>
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
