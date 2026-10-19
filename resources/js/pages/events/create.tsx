import { Head } from '@inertiajs/react';
import { BackButton } from '@/components/common/back-button';
import { EventForm } from '@/components/events/event-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { DateTimeParts, EventOptions } from '@/types';

export default function CreateEvent({
    options,
    defaults,
}: {
    options: EventOptions;
    defaults: {
        responsibleId: number;
        start: DateTimeParts;
        end: DateTimeParts;
    };
}) {
    return (
        <>
            <Head title="Novo evento" />
            <PageContainer>
                <PageHeader
                    title="Novo evento"
                    description="Planeje reuniões, eventos públicos, atos políticos e assembleias."
                    actions={<BackButton />}
                />
                <EventForm
                    options={options}
                    responsibleId={defaults.responsibleId}
                    dateTime={{
                        start: defaults.start,
                        end: defaults.end,
                    }}
                />
            </PageContainer>
        </>
    );
}

CreateEvent.layout = {
    breadcrumbs: [
        { title: 'Eventos', href: '/eventos' },
        { title: 'Novo evento', href: '/eventos/create' },
    ],
};
