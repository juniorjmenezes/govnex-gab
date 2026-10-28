<?php

namespace App\Services\Modules;

use App\Enums\EntityModule;
use App\Models\Entity;
use App\Models\EntityModuleEvent;
use App\Models\EntityModuleSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntityModuleManager
{
    /** @var array<int, list<string>> */
    private array $activeCache = [];

    /** @return list<string> */
    public function activeFor(Entity|int $entidade): array
    {
        $entidadeId = $entidade instanceof Entity ? $entidade->id : $entidade;

        return $this->activeCache[$entidadeId] ??= array_values(EntityModuleSubscription::query()
            ->where('entidade_id', $entidadeId)
            ->where('contratado', true)
            ->where('ativo', true)
            ->pluck('modulo')
            ->map(fn (EntityModule|string $module): string => $module instanceof EntityModule
                ? $module->value
                : $module)
            ->values()
            ->all());
    }

    public function isActive(Entity|int|null $entidade, EntityModule $module): bool
    {
        return $entidade !== null
            && in_array($module->value, $this->activeFor($entidade), true);
    }

    /** @return list<string> */
    public function contractedFor(Entity|int $entidade): array
    {
        $entidadeId = $entidade instanceof Entity ? $entidade->id : $entidade;

        return array_values(EntityModuleSubscription::query()
            ->where('entidade_id', $entidadeId)
            ->where('contratado', true)
            ->pluck('modulo')
            ->map(fn (EntityModule|string $module): string => $module instanceof EntityModule
                ? $module->value
                : $module)
            ->values()
            ->all());
    }

    /** @param list<string> $selection */
    public function syncActivation(Entity $entidade, array $selection, User $actor): void
    {
        abort_unless($actor->canManageEntidade($entidade->id), 403);
        $selection = $this->validateActivation($entidade, $selection);

        DB::transaction(function () use ($entidade, $selection, $actor): void {
            $settings = EntityModuleSubscription::query()
                ->where('entidade_id', $entidade->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (EntityModuleSubscription $setting): string => $setting->modulo->value);

            foreach (EntityModule::cases() as $module) {
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

                EntityModuleEvent::query()->create([
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
    public function validateActivation(Entity $entidade, array $selection): array
    {
        $known = array_column(EntityModule::cases(), 'value');
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

        if (in_array(EntityModule::WhatsApp->value, $selection, true)
            && array_intersect($selection, [
                EntityModule::Demands->value,
                EntityModule::Schedule->value,
                EntityModule::Politics->value,
            ]) === []) {
            throw ValidationException::withMessages([
                'modules' => 'WhatsApp exige Demandas, Agenda ou Inteligência política.',
            ]);
        }

        if (in_array(EntityModule::LegislativeSecretariat->value, $selection, true)
            && array_diff([
                EntityModule::Schedule->value,
                EntityModule::KnowledgeBase->value,
            ], $selection) !== []) {
            throw ValidationException::withMessages([
                'modules' => 'Secretaria legislativa exige Agenda e Base de Conhecimento.',
            ]);
        }

        return array_values(array_filter(
            $known,
            fn (string $module): bool => in_array($module, $selection, true),
        ));
    }
}
