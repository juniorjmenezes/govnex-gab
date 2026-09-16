import { Head, router } from '@inertiajs/react';
import { AppointmentFields } from '@/components/appointments/appointment-fields';
import { useAppointmentForm } from '@/components/appointments/use-appointment-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type { AppointmentCapabilities, AppointmentOptions } from '@/types';

/**
 * Novo compromisso em página própria: são campos demais (participantes,
 * recorrência, lembretes) para caber num modal. A edição continua no modal
 * da agenda, com os mesmos campos.
 */
export default function CreateAppointment({
    options,
    capabilities,
    today,
    whatsappRealEnabled,
    defaults,
}: {
    options: AppointmentOptions;
    capabilities: AppointmentCapabilities;
    today: string;
    whatsappRealEnabled: boolean;
    defaults: { date: string };
}) {
    const tenantUrl = useTenantUrl();
    const appointmentForm = useAppointmentForm({
        options,
        capabilities,
        today,
        initialDate: defaults.date,
    });

    return (
        <>
            <Head title="Novo compromisso" />
            <PageContainer>
                <PageHeader
                    title="Novo compromisso"
                    description="Reuniões e visitas do gabinete, com participantes e lembretes."
                />
                <form
                    noValidate
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        appointmentForm.submit();
                    }}
                >
                    <AppointmentFields
                        state={appointmentForm}
                        options={options}
                        today={today}
                        capabilities={capabilities}
                        whatsappRealEnabled={whatsappRealEnabled}
                        layout="page"
                    />

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(tenantUrl('/agenda'))}
                        >
                            Cancelar
                        </Button>
                        <Button disabled={appointmentForm.form.processing}>
                            Salvar compromisso
                        </Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

CreateAppointment.layout = {
    breadcrumbs: [
        { title: 'Agenda', href: '/agenda' },
        { title: 'Novo compromisso', href: '/agenda/novo' },
    ],
};
