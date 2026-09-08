<?php

namespace App\Services\Entidades;

use App\Enums\EntidadeModule;
use App\Enums\LicenseStatus;
use App\Models\Entidade;
use App\Models\EntidadeLicenca;
use App\Models\EntidadeModulo;
use App\Models\PlanoComercialVersao;
use Illuminate\Support\Facades\DB;

class EntidadeEntitlementService
{
    public function provisionLegacyCompatible(Entidade $entidade): void
    {
        $version = PlanoComercialVersao::query()
            ->whereHas('plano', fn ($query) => $query->where('codigo', 'LEGADO_COMPLETO'))
            ->latest('versao')
            ->firstOrFail();

        DB::transaction(function () use ($entidade, $version): void {
            EntidadeLicenca::query()->firstOrCreate(
                ['entidade_id' => $entidade->id, 'plano_versao_id' => $version->id],
                [
                    'status' => LicenseStatus::Active,
                    'inicio_em' => now(),
                    'cotas_snapshot' => $version->cotas,
                ],
            );

            foreach (EntidadeModule::cases() as $module) {
                EntidadeModulo::query()->firstOrCreate(
                    ['entidade_id' => $entidade->id, 'modulo' => $module],
                    [
                        'escopo' => $module->scope(),
                        'contratado' => true,
                        'ativo' => true,
                        'ativado_em' => now(),
                    ],
                );
            }
        });
    }
}
