<?php

namespace App\Services\Modules;

use App\Enums\GabineteModule;
use App\Enums\GabineteType;

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

    /**
     * Módulos que um gabinete do tipo pode ativar (`GabineteModule::availableFor()`).
     *
     * @return list<GabineteModule>
     */
    public function availableFor(?GabineteType $tipo): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GabineteModule $module): bool => $module->availableFor($tipo),
        ));
    }

    /**
     * Sem tipo, o catálogo inteiro; com tipo, só o que aquele gabinete pode ativar.
     *
     * @return array<string, array{code: string, name: string, description: string, dependencies: list<string>, any_of: list<string>}>
     */
    public function definitions(?GabineteType $tipo = null): array
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
     * @return list<GabineteModule>
     */
    public function dependencies(GabineteModule $module, ?GabineteType $tipo = null): array
    {
        return match ($module) {
            GabineteModule::Schedule => GabineteModule::Relationship->availableFor($tipo)
                ? [GabineteModule::Relationship]
                : [],
            GabineteModule::Demands,
            GabineteModule::Attendances,
            GabineteModule::Events => [GabineteModule::Relationship],
            GabineteModule::Reports => [GabineteModule::Demands],
            GabineteModule::LegislativeSecretariat => [GabineteModule::Schedule, GabineteModule::KnowledgeBase],
            default => [],
        };
    }

    /**
     * Basta um dos módulos de origem; com tipo, só os que ele pode ativar
     * (na Secretaria legislativa, a Agenda).
     *
     * @return list<GabineteModule>
     */
    public function anyOfDependencies(GabineteModule $module, ?GabineteType $tipo = null): array
    {
        if ($module !== GabineteModule::WhatsApp) {
            return [];
        }

        return array_values(array_filter(
            [GabineteModule::Demands, GabineteModule::Schedule, GabineteModule::Politics],
            fn (GabineteModule $dependency): bool => $tipo === null || $dependency->availableFor($tipo),
        ));
    }

    /** @param list<string> $selection
     * @return array<string, string>
     */
    public function errors(array $selection, ?GabineteType $tipo = null): array
    {
        $selected = array_fill_keys($selection, true);
        $errors = [];

        foreach ($this->all() as $module) {
            if (! isset($selected[$module->value])) {
                continue;
            }

            $missing = array_values(array_filter(
                $this->dependencies($module, $tipo),
                fn (GabineteModule $dependency): bool => ! isset($selected[$dependency->value]),
            ));

            if ($missing !== []) {
                $errors[$module->value] = sprintf(
                    '%s exige %s.',
                    $module->label(),
                    implode(', ', array_map(fn (GabineteModule $item): string => $item->label(), $missing)),
                );
            }

            $anyOf = $this->anyOfDependencies($module, $tipo);
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
