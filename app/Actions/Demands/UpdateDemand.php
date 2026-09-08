<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandPriority;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\User;
use App\Services\Demands\DemandNotificationService;
use App\Services\Demands\DemandTimelineRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateDemand
{
    public function __construct(
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Demanda $demand, array $data, User $user): Demanda
    {
        return DB::transaction(function () use ($demand, $data, $user): Demanda {
            $keys = array_map('strval', array_keys($data));
            $before = $this->rawValues($demand, $keys);
            $demand->update($data);
            $demand->refresh();
            $after = $this->rawValues($demand, $keys);
            $changes = array_filter(
                $after,
                fn (mixed $value, string $key): bool => ($before[$key] ?? null) !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changes === []) {
                return $demand;
            }

            $changedKeys = array_map('strval', array_keys($changes));
            $this->recordSpecificChanges($demand, $user, $before, $after, $changedKeys);

            if (in_array('responsavel_id', $changedKeys, true)) {
                $this->notifications->assigned($demand, is_numeric($before['responsavel_id'] ?? null) ? (int) $before['responsavel_id'] : null);
            }

            $specific = ['prioridade', 'responsavel_id', 'prazo', 'cidadao_id'];
            $generalKeys = array_values(array_diff($changedKeys, $specific));

            if ($generalKeys !== []) {
                $this->timeline->record(
                    $demand,
                    $user,
                    DemandEventType::Atualizada,
                    "Dados da demanda {$demand->protocolo} atualizados.",
                    ['antes' => Arr::only($before, $generalKeys), 'depois' => Arr::only($after, $generalKeys)],
                );
            }

            return $demand;
        });
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function rawValues(Demanda $demand, array $keys): array
    {
        return collect($keys)->mapWithKeys(
            fn (string $key): array => [$key => $demand->getRawOriginal($key)],
        )->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $changedKeys
     */
    private function recordSpecificChanges(
        Demanda $demand,
        User $user,
        array $before,
        array $after,
        array $changedKeys,
    ): void {
        if (in_array('prioridade', $changedKeys, true)) {
            $old = is_string($before['prioridade']) ? DemandPriority::tryFrom($before['prioridade']) : null;
            $new = is_string($after['prioridade']) ? DemandPriority::tryFrom($after['prioridade']) : null;
            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::PrioridadeAlterada,
                'Prioridade alterada de '.($old?->label() ?? 'não informada').' para '.($new?->label() ?? 'não informada').'.',
                ['de' => $before['prioridade'], 'para' => $after['prioridade']],
            );
        }

        if (in_array('responsavel_id', $changedKeys, true)) {
            $oldName = $this->userName($before['responsavel_id']);
            $newName = $this->userName($after['responsavel_id']);
            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::ResponsavelAlterado,
                'Responsável alterado de '.($oldName ?? 'não atribuído').' para '.($newName ?? 'não atribuído').'.',
                ['de' => $before['responsavel_id'], 'para' => $after['responsavel_id']],
            );
        }

        if (in_array('prazo', $changedKeys, true)) {
            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::PrazoAlterado,
                'Prazo previsto da demanda alterado.',
                ['de' => $before['prazo'], 'para' => $after['prazo']],
            );
        }

        if (in_array('cidadao_id', $changedKeys, true)) {
            $oldName = $this->citizenName($before['cidadao_id']);
            $newName = $this->citizenName($after['cidadao_id']);
            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::CidadaoAlterado,
                'Solicitante alterado de '.($oldName ?? 'não informado').' para '.($newName ?? 'não informado').'.',
                ['de' => $before['cidadao_id'], 'para' => $after['cidadao_id']],
            );
        }
    }

    private function userName(mixed $id): ?string
    {
        return is_numeric($id) ? User::query()->whereKey((int) $id)->first()?->name : null;
    }

    private function citizenName(mixed $id): ?string
    {
        return is_numeric($id) ? Cidadao::query()->whereKey((int) $id)->first()?->nome : null;
    }
}
