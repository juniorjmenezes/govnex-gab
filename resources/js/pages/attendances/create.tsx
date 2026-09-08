import { Head } from '@inertiajs/react';
import { AttendanceForm } from '@/components/attendances/attendance-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { AttendanceOptions } from '@/types';

export default function CreateAttendance({
    options,
    defaults,
}: {
    options: AttendanceOptions;
    defaults: {
        citizenId: number | null;
        attendantId: number;
        attendedAt: string;
    };
}) {
    return (
        <>
            <Head title="Novo atendimento presencial" />
            <PageContainer>
                <PageHeader
                    title="Novo atendimento presencial"
                    description="Registre a visita realizada no gabinete e as providências adotadas."
                />
                <AttendanceForm
                    options={options}
                    defaults={defaults}
                    attendedAt={defaults.attendedAt}
                />
            </PageContainer>
        </>
    );
}

CreateAttendance.layout = {
    breadcrumbs: [
        { title: 'Atendimentos', href: '/atendimentos' },
        { title: 'Novo atendimento', href: '/atendimentos/create' },
    ],
};
