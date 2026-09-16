import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { Card } from '@/components/ui/card';
import {
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Aparência" />

            <Card className="gap-0 py-0">
                <SurfaceHeader help="A escolha vale para este navegador. Em “Sistema”, o tema acompanha a configuração do dispositivo.">
                    <SurfaceTitle>Tema</SurfaceTitle>
                    <SurfaceDescription>
                        Como a interface será exibida
                    </SurfaceDescription>
                </SurfaceHeader>
                <div className="p-5">
                    <AppearanceTabs />
                </div>
            </Card>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Aparência',
            href: editAppearance(),
        },
    ],
};
