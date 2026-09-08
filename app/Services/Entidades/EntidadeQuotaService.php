<?php

namespace App\Services\Entidades;

use App\Enums\EntidadeQuota;
use App\Models\EntidadeConsumo;
use App\Models\EntidadeLicenca;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntidadeQuotaService
{
    public function currentLicense(int $entidadeId): ?EntidadeLicenca
    {
        return EntidadeLicenca::query()
            ->where('entidade_id', $entidadeId)
            ->where('inicio_em', '<=', now())
            ->where(fn ($query) => $query->whereNull('fim_em')->orWhere('fim_em', '>', now()))
            ->latest('inicio_em')
            ->first();
    }

    public function assertNewActionsAllowed(int $entidadeId): EntidadeLicenca
    {
        $license = $this->currentLicense($entidadeId);
        if ($license === null || ! $license->permitsNewActions()) {
            throw ValidationException::withMessages([
                'license' => 'A licença não permite novas ações. Consulta, exportação e regularização continuam disponíveis.',
            ]);
        }

        return $license;
    }

    public function assertAvailable(int $entidadeId, EntidadeQuota $quota, int $additional = 1): void
    {
        $license = $this->assertNewActionsAllowed($entidadeId);
        $limit = $license->effectiveQuotas()[$quota->value] ?? null;

        if ($limit === null || ! $quota->blocksWhenExceeded()) {
            return;
        }

        $usage = $this->usage($entidadeId, $quota);
        if ($usage + $additional > (int) $limit) {
            throw ValidationException::withMessages([
                'quota' => sprintf('A cota de %s foi atingida. Regularize a licença antes de criar novos registros.', $quota->value),
            ]);
        }
    }

    public function increment(int $entidadeId, EntidadeQuota $quota, int $quantity = 1): void
    {
        $period = $this->period($quota);
        DB::table('entidade_consumos')->insertOrIgnore([
            'entidade_id' => $entidadeId,
            'metrica' => $quota->value,
            'periodo' => $period,
            'quantidade' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('entidade_consumos')
            ->where('entidade_id', $entidadeId)
            ->where('metrica', $quota->value)
            ->where('periodo', $period)
            ->increment('quantidade', $quantity, ['updated_at' => now()]);
    }

    public function reserve(int $entidadeId, EntidadeQuota $quota, int $quantity = 1): void
    {
        DB::transaction(function () use ($entidadeId, $quota, $quantity): void {
            $license = EntidadeLicenca::query()
                ->where('entidade_id', $entidadeId)
                ->where('inicio_em', '<=', now())
                ->where(fn ($query) => $query->whereNull('fim_em')->orWhere('fim_em', '>', now()))
                ->latest('inicio_em')
                ->lockForUpdate()
                ->first();
            if ($license === null || ! $license->permitsNewActions()) {
                throw ValidationException::withMessages([
                    'license' => 'A licença não permite novas ações.',
                ]);
            }

            $period = $this->period($quota);
            DB::table('entidade_consumos')->insertOrIgnore([
                'entidade_id' => $entidadeId,
                'metrica' => $quota->value,
                'periodo' => $period,
                'quantidade' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $usage = EntidadeConsumo::query()
                ->where('entidade_id', $entidadeId)
                ->where('metrica', $quota)
                ->where('periodo', $period)
                ->lockForUpdate()
                ->firstOrFail();
            $limit = $license->effectiveQuotas()[$quota->value] ?? null;
            if ($limit !== null && $quota->blocksWhenExceeded()
                && $usage->quantidade + $quantity > (int) $limit) {
                throw ValidationException::withMessages([
                    'quota' => sprintf('A cota de %s foi atingida.', $quota->value),
                ]);
            }
            $usage->increment('quantidade', $quantity);
        }, 3);
    }

    public function release(int $entidadeId, EntidadeQuota $quota, int $quantity = 1): void
    {
        $period = $this->period($quota);
        DB::transaction(function () use ($entidadeId, $quota, $quantity, $period): void {
            $usage = EntidadeConsumo::query()
                ->where('entidade_id', $entidadeId)
                ->where('metrica', $quota)
                ->where('periodo', $period)
                ->lockForUpdate()
                ->first();
            if ($usage === null || $usage->quantidade === 0) {
                return;
            }
            $usage->forceFill(['quantidade' => max(0, $usage->quantidade - $quantity)])->save();
        }, 3);
    }

    public function usage(int $entidadeId, EntidadeQuota $quota): int
    {
        return match ($quota) {
            EntidadeQuota::ActiveGabinetes => DB::table('gabinetes')
                ->where('entidade_id', $entidadeId)
                ->where('status', 'ativo')
                ->whereNull('deleted_at')
                ->count(),
            EntidadeQuota::ActiveUsers => DB::table('entidade_membros')
                ->where('entidade_id', $entidadeId)
                ->where('ativo', true)
                ->distinct('usuario_id')
                ->count('usuario_id'),
            default => (int) EntidadeConsumo::query()
                ->where('entidade_id', $entidadeId)
                ->where('metrica', $quota)
                ->where('periodo', $this->period($quota))
                ->value('quantidade'),
        };
    }

    private function period(EntidadeQuota $quota): string
    {
        return $quota === EntidadeQuota::MonthlyWhatsAppMessages
            ? now()->format('Y-m')
            : 'TOTAL';
    }
}
