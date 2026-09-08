<?php

namespace App\Services\Modules;

use App\Enums\EntidadeModule;
use App\Models\Entidade;
use App\Models\EntidadeModulo;
use App\Models\EntidadeModuloEvento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntidadeModuleManager
{
    /** @var array<int, list<string>> */
    private array $activeCache = [];

    /** @return list<string> */
    public function activeFor(Entidade|int $entidade): array
    {
        $entidadeId = $entidade instanceof Entidade ? $entidade->id : $entidade;

        return $this->activeCache[$entidadeId] ??= array_values(EntidadeModulo::query()
            ->where('entidade_id', $entidadeId)
            ->where('contratado', true)
            ->where('ativo', true)
            ->pluck('modulo')
            ->map(fn (EntidadeModule|string $module): string => $module instanceof EntidadeModule
                ? $module->value
                : $module)
            ->values()
            ->all());
    }

    public function isActive(Entidade|int|null $entidade, EntidadeModule $module): bool
    {
        return $entidade !== null
            && in_array($module->value, $this->activeFor($entidade), true);
    }

    /** @return list<string> */
    public function contractedFor(Entidade|int $entidade): array
    {
        $entidadeId = $entidade instanceof Entidade ? $entidade->id : $entidade;

        return array_values(EntidadeModulo::query()
            ->where('entidade_id', $entidadeId)
            ->where('contratado', true)
            ->pluck('modulo')
            ->map(fn (EntidadeModule|string $module): string => $module instanceof EntidadeModule
                ? $module->value
                : $module)
            ->values()
            ->all());
    }

    /** @param list<string> $selection */
    public function syncActivation(Entidade $entidade, array $selection, User $actor): void
    {
        abort_unless($actor->canManageEntidade($entidade->id), 403);
        $selection = $this->validateActivation($entidade, $selection);

        DB::transaction(function () use ($entidade, $selection, $actor): void {
            $settings = EntidadeModulo::query()
                ->where('entidade_id', $entidade->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (EntidadeModulo $setting): string => $setting->modulo->value);

            foreach (EntidadeModule::cases() as $module) {
                $setting = $settings->get($module->value);
                if ($setting === null) {
                    continue;
                }

                $active = $setting->contratado && in_array($module->value, $selection, true);
                if ($setting->ativo === $active) {
                    continue;
                }

                $setting->forceFill([
                    'ativo' => $active,
                    'ativado_em' => $active ? now() : null,
                    'desativado_em' => $active ? null : now(),
                    'administrador_id' => $actor->id,
                ])->save();

                EntidadeModuloEvento::query()->create([
                    'entidade_id' => $entidade->id,
                    'modulo' => $module->value,
                    'acao' => $active ? 'ATIVADO' : 'DESATIVADO',
                    'administrador_id' => $actor->id,
                    'contexto' => null,
                    'ocorrido_em' => now(),
                ]);
            }
        });

        unset($this->activeCache[$entidade->id]);
    }

    /** @param list<string> $selection
     * @return list<string>
     */
    public function validateActivation(Entidade $entidade, array $selection): array
    {
        $known = array_column(EntidadeModule::cases(), 'value');
        $selection = array_values(array_unique(array_map(
            fn (string $module): string => strtoupper(trim($module)),
            $selection,
        )));
        $unknown = array_values(array_diff($selection, $known));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'modules' => 'Módulos desconhecidos: '.implode(', ', $unknown).'.',
            ]);
        }

        $notContracted = array_values(array_diff($selection, $this->contractedFor($entidade)));
        if ($notContracted !== []) {
            throw ValidationException::withMessages([
                'modules' => 'A licença não inclui: '.implode(', ', $notContracted).'.',
            ]);
        }

        if (in_array(EntidadeModule::WhatsApp->value, $selection, true)
            && array_intersect($selection, [
                EntidadeModule::Demands->value,
                EntidadeModule::Schedule->value,
                EntidadeModule::Politics->value,
            ]) === []) {
            throw ValidationException::withMessages([
                'modules' => 'WhatsApp exige Demandas, Agenda ou Inteligência política.',
            ]);
        }

        return array_values(array_filter(
            $known,
            fn (string $module): bool => in_array($module, $selection, true),
        ));
    }
}
