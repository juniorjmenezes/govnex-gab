import { Head } from '@inertiajs/react';
import { AttendanceForm } from '@/components/attendances/attendance-form';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import type { Attendance, AttendanceOptions } from '@/types';

export default function EditAttendance({
    attendance,
    attendedAtLocal,
    options,
}: {
    attendance: Attendance;
    attendedAtLocal: string;
    options: AttendanceOptions;
}) {
    return (
        <>
            <Head title={`Editar atendimento #${attendance.id}`} />
            <PageContainer>
                <PageHeader
                    title="Editar atendimento"
                    description={`Atualize o registro de ${attendance.cidadao?.nome ?? 'atendimento presencial'}.`}
                />
                <AttendanceForm
                    attendance={attendance}
                    options={options}
                    attendedAt={attendedAtLocal}
                />
            </PageContainer>
        </>
    );
}

EditAttendance.layout = (page: { attendance: Attendance }) => ({
    breadcrumbs: [
        { title: 'Atendimentos', href: '/atendimentos' },
        {
            title: page.attendance.assunto,
            href: `/atendimentos/${page.attendance.id}`,
        },
        { title: 'Editar', href: '#' },
    ],
});
