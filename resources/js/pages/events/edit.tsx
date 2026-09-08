import { Head } from '@inertiajs/react';
import { EventForm } from '@/components/events/event-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { DateTimeParts, EventOptions, OfficeEvent } from '@/types';

export default function EditEvent({
    event,
    options,
    dateTime,
}: {
    event: OfficeEvent;
    options: EventOptions;
    dateTime: { start: DateTimeParts; end: DateTimeParts };
}) {
    return (
        <>
            <Head title={`Editar ${event.titulo}`} />
            <PageContainer>
                <PageHeader
                    title="Editar evento"
                    description={`Atualize as informações de ${event.titulo}.`}
                />
                <EventForm
                    event={event}
                    options={options}
                    dateTime={dateTime}
                />
            </PageContainer>
        </>
    );
}

EditEvent.layout = (page: { event: OfficeEvent }) => ({
    breadcrumbs: [
        { title: 'Eventos', href: '/eventos' },
        {
            title: page.event.titulo,
            href: `/eventos/${page.event.id}`,
        },
        { title: 'Editar', href: '#' },
    ],
});
