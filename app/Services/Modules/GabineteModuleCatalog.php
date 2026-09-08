<?php

namespace App\Services\Modules;

use App\Enums\GabineteModule;

class GabineteModuleCatalog
{
    /** @return list<GabineteModule> */
    public function all(): array
    {
        return GabineteModule::cases();
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_column($this->all(), 'value');
    }

    /** @return array<string, array{code: string, name: string, description: string, dependencies: list<string>, any_of: list<string>}> */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->all() as $module) {
            $definitions[$module->value] = [
                'code' => $module->value,
                'name' => $module->label(),
                'description' => $module->description(),
                'dependencies' => array_column($this->dependencies($module), 'value'),
                'any_of' => array_column($this->anyOfDependencies($module), 'value'),
            ];
        }

        return $definitions;
    }

    /** @return list<GabineteModule> */
    public function dependencies(GabineteModule $module): array
    {
        return match ($module) {
            GabineteModule::Demands,
            GabineteModule::Attendances,
            GabineteModule::Schedule,
            GabineteModule::Events => [GabineteModule::Relationship],
            GabineteModule::Reports => [GabineteModule::Demands],
            default => [],
        };
    }

    /** @return list<GabineteModule> */
    public function anyOfDependencies(GabineteModule $module): array
    {
        return $module === GabineteModule::WhatsApp
            ? [GabineteModule::Demands, GabineteModule::Schedule, GabineteModule::Politics]
            : [];
    }

    /** @param list<string> $selection
     * @return array<string, string>
     */
    public function errors(array $selection): array
    {
        $selected = array_fill_keys($selection, true);
        $errors = [];

        foreach ($this->all() as $module) {
            if (! isset($selected[$module->value])) {
                continue;
            }

            $missing = array_values(array_filter(
                $this->dependencies($module),
                fn (GabineteModule $dependency): bool => ! isset($selected[$dependency->value]),
            ));

            if ($missing !== []) {
                $errors[$module->value] = sprintf(
                    '%s exige %s.',
                    $module->label(),
                    implode(', ', array_map(fn (GabineteModule $item): string => $item->label(), $missing)),
                );
            }

            $anyOf = $this->anyOfDependencies($module);
            if ($anyOf !== [] && ! collect($anyOf)->contains(
                fn (GabineteModule $dependency): bool => isset($selected[$dependency->value]),
            )) {
                $errors[$module->value] = sprintf(
                    '%s exige ao menos um destes módulos: %s.',
                    $module->label(),
                    implode(', ', array_map(fn (GabineteModule $item): string => $item->label(), $anyOf)),
                );
            }
        }

        return $errors;
    }
}
