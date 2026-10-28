<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntityModule;
use App\Enums\EntityQuota;
use App\Enums\LicenseStatus;
use App\Enums\OfficeModule;
use App\Models\CommercialPlanVersion;
use App\Models\EntityModuleSubscription;
use App\Models\Office;
use App\Models\User;
use App\Services\Entidades\EntityQuotaService;
use App\Services\Modules\EntityModuleManager;
use App\Services\Modules\OfficeModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class EntidadeLicensingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_entidade_receives_complete_legacy_compatible_entitlements(): void
    {
        $office = Office::factory()->create();

        $this->assertCount(
            count(EntityModule::availableCases($office->entidade->tipo)),
            app(EntityModuleManager::class)->activeFor($office->entidade_id),
        );
        $this->assertDatabaseHas('entidade_licencas', [
            'entidade_id' => $office->entidade_id,
            'status' => LicenseStatus::Active->value,
        ]);
    }

    public function test_entidade_module_gate_suspends_unit_module_without_deleting_its_configuration(): void
    {
        $office = Office::factory()->create();
        $setting = EntityModuleSubscription::query()
            ->where('entidade_id', $office->entidade_id)
            ->where('modulo', EntityModule::Demands)
            ->firstOrFail();
        $setting->forceFill(['ativo' => false, 'desativado_em' => now()])->save();

        $this->assertFalse(app(OfficeModuleManager::class)->isActive($office, OfficeModule::Demands));
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $office->id,
            'modulo' => OfficeModule::Demands->value,
            'ativo' => true,
        ]);
    }

    public function test_expired_license_blocks_new_actions_but_does_not_delete_or_hide_existing_data(): void
    {
        $office = Office::factory()->create();
        $license = $office->entidade->licencas()->firstOrFail();
        $license->forceFill(['fim_em' => now()->subMinute()])->save();

        $this->expectException(ValidationException::class);
        app(EntityQuotaService::class)->assertNewActionsAllowed($office->entidade_id);

        $this->assertDatabaseHas('entidades', ['id' => $office->entidade_id]);
        $this->assertDatabaseHas('gabinetes', ['id' => $office->id]);
    }

    public function test_blocking_quota_rejects_only_the_next_related_action(): void
    {
        $office = Office::factory()->create();
        $license = $office->entidade->licencas()->firstOrFail();
        $quotas = $license->cotas_snapshot;
        $quotas[EntityQuota::ActiveGabinetes->value] = 1;
        $license->forceFill(['cotas_snapshot' => $quotas])->save();

        try {
            app(EntityQuotaService::class)->assertAvailable(
                $office->entidade_id,
                EntityQuota::ActiveGabinetes,
            );
            $this->fail('A criação acima da cota deveria ter sido bloqueada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quota', $exception->errors());
        }

        $this->assertNotNull(Office::withoutGlobalScopes()->find($office->id));
    }

    public function test_published_plan_version_is_immutable(): void
    {
        $version = CommercialPlanVersion::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $version->forceFill(['versao' => 2])->save();
    }

    public function test_platform_admin_can_change_entidade_activation_with_audit(): void
    {
        $office = Office::factory()->create();
        $admin = User::factory()->root()->create();
        $manager = app(EntityModuleManager::class);
        $selection = array_values(array_filter(
            array_column(EntityModule::availableCases($office->entidade->tipo), 'value'),
            fn (string $module): bool => $module !== EntityModule::Reports->value,
        ));

        $manager->syncActivation($office->entidade, $selection, $admin);

        $this->assertFalse($manager->isActive(
            $office->entidade_id,
            EntityModule::Reports,
        ));
        $this->assertDatabaseHas('entidade_modulo_eventos', [
            'entidade_id' => $office->entidade_id,
            'modulo' => EntityModule::Reports->value,
            'acao' => 'DESATIVADO',
            'administrador_id' => $admin->id,
        ]);
    }
}
