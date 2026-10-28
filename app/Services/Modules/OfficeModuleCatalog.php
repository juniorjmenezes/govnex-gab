<?php

namespace App\Services\Modules;

use App\Enums\OfficeModule;
use App\Enums\OfficeType;

class OfficeModuleCatalog
{
    /** @return list<OfficeModule> */
    public function all(): array
    {
        return OfficeModule::cases();
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_column($this->all(), 'value');
    }

    /**
     * Módulos que um gabinete do tipo pode ativar (`OfficeModule::availableFor()`).
     *
     * @return list<OfficeModule>
     */
    public function availableFor(?OfficeType $tipo): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (OfficeModule $module): bool => $module->availableFor($tipo),
        ));
    }

    /**
     * Sem tipo, o catálogo inteiro; com tipo, só o que aquele gabinete pode ativar.
     *
     * @return array<string, array{code: string, name: string, description: string, dependencies: list<string>, any_of: list<string>}>
     */
    public function definitions(?OfficeType $tipo = null): array
    {
        $definitions = [];

        foreach ($tipo === null ? $this->all() : $this->availableFor($tipo) as $module) {
            $definitions[$module->value] = [
                'code' => $module->value,
                'name' => $module->label(),
                'description' => $module->description(),
                'dependencies' => array_column($this->dependencies($module, $tipo), 'value'),
                'any_of' => array_column($this->anyOfDependencies($module, $tipo), 'value'),
            ];
        }

        return $definitions;
    }

    /**
     * Sem tipo, as dependências de qualquer gabinete. A Agenda só exige
     * Relacionamento onde o tipo oferece Relacionamento: o compromisso liga
     * um cidadão de forma opcional (`compromissos.cidadao_id` nulo), e a
     * Secretaria legislativa, que não atende cidadão, usa a Agenda sem ele.
     *
     * @return list<OfficeModule>
     */
    public function dependencies(OfficeModule $module, ?OfficeType $tipo = null): array
    {
        return match ($module) {
            OfficeModule::Schedule => OfficeModule::Relationship->availableFor($tipo)
                ? [OfficeModule::Relationship]
                : [],
            OfficeModule::Demands,
            OfficeModule::Attendances,
            OfficeModule::Events => [OfficeModule::Relationship],
            OfficeModule::Reports => [OfficeModule::Demands],
            OfficeModule::LegislativeSecretariat => [OfficeModule::Schedule, OfficeModule::KnowledgeBase],
            default => [],
        };
    }

    /**
     * Basta um dos módulos de origem; com tipo, só os que ele pode ativar
     * (na Secretaria legislativa, a Agenda).
     *
     * @return list<OfficeModule>
     */
    public function anyOfDependencies(OfficeModule $module, ?OfficeType $tipo = null): array
    {
        if ($module !== OfficeModule::WhatsApp) {
            return [];
        }

        return array_values(array_filter(
            [OfficeModule::Demands, OfficeModule::Schedule, OfficeModule::Politics],
            fn (OfficeModule $dependency): bool => $tipo === null || $dependency->availableFor($tipo),
        ));
    }

    /** @param list<string> $selection
     * @return array<string, string>
     */
    public function errors(array $selection, ?OfficeType $tipo = null): array
    {
        $selected = array_fill_keys($selection, true);
        $errors = [];

        foreach ($this->all() as $module) {
            if (! isset($selected[$module->value])) {
                continue;
            }

            $missing = array_values(array_filter(
                $this->dependencies($module, $tipo),
                fn (OfficeModule $dependency): bool => ! isset($selected[$dependency->value]),
            ));

            if ($missing !== []) {
                $errors[$module->value] = sprintf(
                    '%s exige %s.',
                    $module->label(),
                    implode(', ', array_map(fn (OfficeModule $item): string => $item->label(), $missing)),
                );
            }

            $anyOf = $this->anyOfDependencies($module, $tipo);
            if ($anyOf !== [] && ! collect($anyOf)->contains(
                fn (OfficeModule $dependency): bool => isset($selected[$dependency->value]),
            )) {
                $errors[$module->value] = sprintf(
                    '%s exige ao menos um destes módulos: %s.',
                    $module->label(),
                    implode(', ', array_map(fn (OfficeModule $item): string => $item->label(), $anyOf)),
                );
            }
        }

        return $errors;
    }
}
