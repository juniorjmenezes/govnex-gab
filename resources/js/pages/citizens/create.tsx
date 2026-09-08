import { Head } from '@inertiajs/react';
import { CitizenForm } from '@/components/citizens/citizen-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Card } from '@/components/ui/card';
import type { Neighborhood, OfficeLocation } from '@/types';

export default function CreateCitizen({
    neighborhoods,
    officeLocation,
    whatsappConsentText,
}: {
    neighborhoods: Neighborhood[];
    officeLocation: OfficeLocation;
    whatsappConsentText: string;
}) {
    return (
        <>
            <Head title="Novo cidadão" />
            <PageContainer>
                <PageHeader
                    title="Novo cidadão"
                    description="Registre os dados de contato e localização para agilizar os atendimentos."
                />
                <Card className="p-5">
                    <CitizenForm
                        neighborhoods={neighborhoods}
                        officeLocation={officeLocation}
                        whatsappConsentText={whatsappConsentText}
                    />
                </Card>
            </PageContainer>
        </>
    );
}

CreateCitizen.layout = {
    breadcrumbs: [
        { title: 'Cidadãos', href: '/cidadaos' },
        { title: 'Novo cidadão', href: '/cidadaos/create' },
    ],
};
