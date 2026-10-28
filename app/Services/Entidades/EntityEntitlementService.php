<?php

namespace App\Services\Entidades;

use App\Enums\EntityModule;
use App\Enums\LicenseStatus;
use App\Models\CommercialPlanVersion;
use App\Models\Entity;
use App\Models\EntityLicense;
use App\Models\EntityModuleSubscription;
use Illuminate\Support\Facades\DB;

class EntityEntitlementService
{
    public function provisionLegacyCompatible(Entity $entidade): void
    {
        $version = CommercialPlanVersion::query()
            ->whereHas('plano', fn ($query) => $query->where('codigo', 'LEGADO_COMPLETO'))
            ->latest('versao')
            ->firstOrFail();

        DB::transaction(function () use ($entidade, $version): void {
            EntityLicense::query()->firstOrCreate(
                ['entidade_id' => $entidade->id, 'plano_versao_id' => $version->id],
                [
                    'status' => LicenseStatus::Active,
                    'inicio_em' => now(),
                    'cotas_snapshot' => $version->cotas,
                ],
            );

            foreach (EntityModule::availableCases($entidade->tipo) as $module) {
                $active = $module->activeByDefault();

                EntityModuleSubscription::query()->firstOrCreate(
                    ['entidade_id' => $entidade->id, 'modulo' => $module],
                    [
                        'escopo' => $module->scope(),
                        'contratado' => true,
                        'ativo' => $active,
                        'ativado_em' => $active ? now() : null,
                    ],
                );
            }
        });
    }
}
