import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Switch } from '@/components/ui/switch';
import type { GabineteModuleCode, GabineteModuleDefinition } from '@/types';

export function toggleOfficeModule(
    selected: GabineteModuleCode[],
    module: GabineteModuleCode,
    checked: boolean,
): GabineteModuleCode[] {
    return checked
        ? [...new Set([...selected, module])]
        : selected.filter((item) => item !== module);
}

export function getOfficeModuleSelectionErrors(
    selected: GabineteModuleCode[],
    catalog: GabineteModuleDefinition[],
): string[] {
    const active = new Set(selected);

    return catalog.flatMap((module) => {
        if (!active.has(module.code)) {
            return [];
        }

        const missing = module.dependencies.filter(
            (dependency) => !active.has(dependency),
        );

        if (missing.length > 0) {
            return [
                `${module.name} exige ${moduleNames(missing, catalog).join(', ')}.`,
            ];
        }

        if (
            module.any_of.length > 0 &&
            !module.any_of.some((dependency) => active.has(dependency))
        ) {
            return [
                `${module.name} exige ao menos um destes módulos: ${moduleNames(module.any_of, catalog).join(', ')}.`,
            ];
        }

        return [];
    });
}

function moduleNames(
    codes: GabineteModuleCode[],
    catalog: GabineteModuleDefinition[],
): string[] {
    return codes.map(
        (code) => catalog.find((item) => item.code === code)?.name ?? code,
    );
}

export function OfficeModuleSelector({
    catalog,
    selected,
    onToggle,
    errors,
    idPrefix,
}: {
    catalog: GabineteModuleDefinition[];
    selected: GabineteModuleCode[];
    onToggle: (module: GabineteModuleCode, checked: boolean) => void;
    errors: string[];
    idPrefix: string;
}) {
    return (
        <div className="space-y-4">
            <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                {catalog.map((module) => {
                    const dependencies = moduleNames(
                        module.dependencies,
                        catalog,
                    ).join(', ');
                    const alternatives = moduleNames(
                        module.any_of,
                        catalog,
                    ).join(', ');
                    const inputId = `${idPrefix}-${module.code}`;
                    const nameId = `${inputId}-name`;
                    const descriptionId = `${inputId}-description`;

                    return (
                        <div key={module.code} className="rounded-md border">
                            <div className="flex min-h-14 items-center justify-between gap-3 p-3">
                                <span
                                    id={nameId}
                                    className="truncate text-sm font-medium"
                                >
                                    {module.name}
                                </span>
                                <Switch
                                    id={inputId}
                                    className="shrink-0"
                                    checked={selected.includes(module.code)}
                                    aria-labelledby={nameId}
                                    aria-describedby={descriptionId}
                                    onCheckedChange={(checked) =>
                                        onToggle(module.code, checked)
                                    }
                                />
                            </div>
                            <div
                                id={descriptionId}
                                className="space-y-1 border-t px-3 py-2 text-xs text-muted-foreground"
                            >
                                <p>{module.description}</p>
                                {dependencies !== '' && (
                                    <p>Dependências: {dependencies}</p>
                                )}
                                {alternatives !== '' && (
                                    <p>Exige pelo menos um: {alternatives}</p>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
            {errors.length > 0 && (
                <div className="px-4 pb-4">
                    <Alert variant="destructive">
                        <AlertTitle>Combinação incompatível</AlertTitle>
                        <AlertDescription>
                            {errors.map((error) => (
                                <p key={error}>{error}</p>
                            ))}
                        </AlertDescription>
                    </Alert>
                </div>
            )}
        </div>
    );
}
