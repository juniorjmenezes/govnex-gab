import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    getOfficeModuleSelectionErrors,
    OfficeModuleSelector,
    toggleOfficeModule,
} from '@/components/admin/office-module-selector';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import type {
    GabineteModuleCode,
    GabineteModuleDefinition,
    GabineteModuleEvent,
} from '@/types';

type Props = {
    office: { id: number; name: string };
    selectedModules: GabineteModuleCode[];
    moduleCatalog: GabineteModuleDefinition[];
    moduleHistory: GabineteModuleEvent[];
};

export default function OfficeModules({
    office,
    selectedModules: initialModules,
    moduleCatalog,
    moduleHistory,
}: Props) {
    const [selectedModules, setSelectedModules] =
        useState<GabineteModuleCode[]>(initialModules);
    const [isSaving, setIsSaving] = useState(false);
    const errors = getOfficeModuleSelectionErrors(
        selectedModules,
        moduleCatalog,
    );

    const save = () => {
        if (errors.length > 0) {
            return;
        }

        setIsSaving(true);
        router.patch(
            `/admin/gabinetes/${office.id}/modulos`,
            { modules: selectedModules },
            { preserveScroll: true, onFinish: () => setIsSaving(false) },
        );
    };

    return (
        <>
            <Head title={`Módulos de ${office.name}`} />
            <PageContainer>
                <PageHeader
                    title={`Módulos de ${office.name}`}
                    description="Defina as áreas disponíveis para este gabinete. Os dados dos módulos desativados serão preservados."
                />

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader help="Os dados dos módulos desativados são preservados.">
                        <SurfaceTitle>Módulos do gabinete</SurfaceTitle>
                    </SurfaceHeader>
                    <OfficeModuleSelector
                        catalog={moduleCatalog}
                        selected={selectedModules}
                        errors={errors}
                        idPrefix="office-module"
                        onToggle={(module, checked) =>
                            setSelectedModules((current) =>
                                toggleOfficeModule(current, module, checked),
                            )
                        }
                    />
                </Surface>

                {moduleHistory.length > 0 && (
                    <Surface as="section" className="overflow-hidden">
                        <SurfaceHeader>
                            <SurfaceTitle>Alterações recentes</SurfaceTitle>
                        </SurfaceHeader>
                        <ul className="divide-y">
                            {moduleHistory.map((event) => (
                                <li
                                    key={event.id}
                                    className="px-4 py-3 text-sm text-muted-foreground"
                                >
                                    {moduleCatalog.find(
                                        (module) =>
                                            module.code === event.module,
                                    )?.name ?? event.module}{' '}
                                    {event.action === 'ATIVADO'
                                        ? 'ativado'
                                        : 'desativado'}{' '}
                                    por {event.administrator ?? 'sistema'} em{' '}
                                    {new Date(event.occurred_at).toLocaleString(
                                        'pt-BR',
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Surface>
                )}

                <div className="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get('/admin/gabinetes')}
                    >
                        Voltar
                    </Button>
                    <Button
                        onClick={save}
                        disabled={isSaving || errors.length > 0}
                    >
                        {isSaving ? 'Salvando...' : 'Salvar módulos'}
                    </Button>
                </div>
            </PageContainer>
        </>
    );
}

OfficeModules.layout = {
    breadcrumbs: [
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        { title: 'Módulos', href: '#' },
    ],
};
