<?php

namespace App\Services\Modules;

use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Models\Gabinete;
use App\Models\GabineteModulo;
use App\Models\GabineteModuloEvento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GabineteModuleManager
{
    /** @var array<int, list<string>> */
    private array $activeCache = [];

    public function __construct(
        private readonly GabineteModuleCatalog $catalog,
        private readonly EntidadeModuleManager $entidadeModules,
    ) {}

    /**
     * Módulos com que um gabinete do tipo nasce: tudo o que ele pode ativar.
     * Sem tipo, o padrão de qualquer gabinete (sem os módulos exclusivos de
     * um tipo, como a Secretaria legislativa).
     *
     * @return list<string>
     */
    public function allEnabled(?GabineteType $tipo = null): array
    {
        return array_column($this->catalog->availableFor($tipo), 'value');
    }

    /** @return list<string> */
    public function activeFor(Gabinete|int $office): array
    {
        $officeId = $office instanceof Gabinete ? $office->id : $office;

        if (array_key_exists($officeId, $this->activeCache)) {
            return $this->activeCache[$officeId];
        }

        $officeModel = $office instanceof Gabinete
            ? $office
            : Gabinete::withoutGlobalScopes()->select(['id', 'entidade_id'])->find($officeId);
        $entidadeActive = $officeModel?->entidade_id
            ? $this->entidadeModules->activeFor((int) $officeModel->entidade_id)
            : $this->catalog->values();

        $active = GabineteModulo::query()
            ->where('gabinete_id', $officeId)
            ->where('ativo', true)
            ->pluck('modulo')
            ->map(fn (GabineteModule|string $module): string => $module instanceof GabineteModule
                ? $module->value
                : $module)
            ->all();

        return $this->activeCache[$officeId] = array_values(array_filter(
            $this->catalog->values(),
            fn (string $module): bool => in_array($module, $active, true)
                && in_array($module, $entidadeActive, true),
        ));
    }

    public function isActive(Gabinete|int|null $office, GabineteModule $module): bool
    {
        if ($office === null) {
            return false;
        }

        return in_array($module->value, $this->activeFor($office), true);
    }

    public function anyActiveOffice(GabineteModule $module): bool
    {
        return GabineteModulo::query()
            ->where('modulo', $module->value)
            ->where('ativo', true)
            ->whereHas('gabinete', fn ($query) => $query
                ->where('status', 'ativo')
                ->whereHas('entidade.modulos', fn ($query) => $query
                    ->where('modulo', $module->value)
                    ->where('contratado', true)
                    ->where('ativo', true)))
            ->exists();
    }

    /** @return list<int> */
    public function activeOfficeIds(GabineteModule $module): array
    {
        return array_values(GabineteModulo::query()
            ->where('modulo', $module->value)
            ->where('ativo', true)
            ->whereHas('gabinete', fn ($query) => $query
                ->where('status', 'ativo')
                ->whereHas('entidade.modulos', fn ($query) => $query
                    ->where('modulo', $module->value)
                    ->where('contratado', true)
                    ->where('ativo', true)))
            ->pluck('gabinete_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /** @param list<string> $selection
     * @param  GabineteType|null  $tipo  sem tipo, as dependências de qualquer gabinete
     * @return list<string>
     */
    public function validateSelection(array $selection, ?GabineteType $tipo = null): array
    {
        $allowed = array_fill_keys($this->catalog->values(), true);
        $normalized = [];
        $unknown = [];

        foreach ($selection as $module) {
            $value = strtoupper(trim($module));
            if (! isset($allowed[$value])) {
                $unknown[] = $module;

                continue;
            }

            $normalized[$value] = true;
        }

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'modules' => 'Módulos desconhecidos: '.implode(', ', array_unique($unknown)).'.',
            ]);
        }

        $ordered = array_values(array_filter(
            $this->catalog->values(),
            fn (string $module): bool => isset($normalized[$module]),
        ));
        $errors = $this->catalog->errors($ordered, $tipo);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'modules' => array_values($errors),
            ]);
        }

        return $ordered;
    }

    /** @param list<string> $selection
     * @param  array<string, scalar|null>  $context
     * @param  User|null  $administrator  nulo quando quem liga é o espelhamento do Govnex Hub, não uma pessoa
     */
    public function sync(Gabinete $office, array $selection, ?User $administrator, array $context = []): void
    {
        $selection = $this->validateSelection($selection, $office->tipo_gabinete);

        $unavailable = array_values(array_filter(
            $selection,
            fn (string $module): bool => ! GabineteModule::from($module)->availableFor($office->tipo_gabinete),
        ));
        if ($unavailable !== []) {
            throw ValidationException::withMessages([
                'modules' => 'Este tipo de gabinete não aceita: '.implode(', ', array_map(
                    fn (string $module): string => GabineteModule::from($module)->label(),
                    $unavailable,
                )).'.',
            ]);
        }

        $selected = array_fill_keys($selection, true);

        DB::transaction(function () use ($office, $administrator, $selected, $context): void {
            $current = GabineteModulo::query()
                ->where('gabinete_id', $office->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (GabineteModulo $setting): string => $setting->modulo->value);

            foreach ($this->catalog->all() as $module) {
                $setting = $current->get($module->value);

                // Módulos que o tipo não aceita nem ganham linha desligada; a
                // linha anterior à restrição do tipo é desligada (já foram
                // recusados acima, então nunca estão na seleção).
                if ($setting === null && ! $module->availableFor($office->tipo_gabinete)) {
                    continue;
                }

                $enabled = isset($selected[$module->value]);
                $changed = $setting === null || $setting->ativo !== $enabled;

                if ($setting === null) {
                    $setting = new GabineteModulo;
                    $setting->forceFill([
                        'gabinete_id' => $office->id,
                        'modulo' => $module->value,
                    ]);
                }

                $setting->forceFill([
                    'ativo' => $enabled,
                    'ativado_em' => $enabled ? ($setting->ativado_em ?? now()) : null,
                    'desativado_em' => $enabled ? null : now(),
                    'administrador_id' => $administrator?->id,
                ])->save();

                if ($changed) {
                    GabineteModuloEvento::query()->create([
                        'gabinete_id' => $office->id,
                        'modulo' => $module->value,
                        'acao' => $enabled ? 'ATIVADO' : 'DESATIVADO',
                        'administrador_id' => $administrator?->id,
                        'contexto' => $context,
                        'ocorrido_em' => now(),
                    ]);
                }
            }
        });

        unset($this->activeCache[$office->id]);
    }
}
