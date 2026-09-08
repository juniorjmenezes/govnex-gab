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
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                        <div
                            key={module.code}
                            className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3"
                        >
                            <span className="min-w-0 space-y-1">
                                <span
                                    id={nameId}
                                    className="block text-sm font-medium"
                                >
                                    {module.name}
                                </span>
                                <span
                                    id={descriptionId}
                                    className="block text-xs text-muted-foreground"
                                >
                                    {module.description}
                                </span>
                                {dependencies !== '' && (
                                    <span className="block text-xs text-muted-foreground">
                                        Dependências: {dependencies}
                                    </span>
                                )}
                                {alternatives !== '' && (
                                    <span className="block text-xs text-muted-foreground">
                                        Exige pelo menos um: {alternatives}
                                    </span>
                                )}
                            </span>
                            <Switch
                                id={inputId}
                                checked={selected.includes(module.code)}
                                aria-labelledby={nameId}
                                aria-describedby={descriptionId}
                                onCheckedChange={(checked) =>
                                    onToggle(module.code, checked)
                                }
                            />
                        </div>
                    );
                })}
            </div>
            {errors.length > 0 && (
                <Alert variant="destructive">
                    <AlertTitle>Combinação incompatível</AlertTitle>
                    <AlertDescription>
                        {errors.map((error) => (
                            <p key={error}>{error}</p>
                        ))}
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}
