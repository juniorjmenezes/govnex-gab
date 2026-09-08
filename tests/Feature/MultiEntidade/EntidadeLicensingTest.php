<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntidadeModule;
use App\Enums\EntidadeQuota;
use App\Enums\GabineteModule;
use App\Enums\LicenseStatus;
use App\Models\EntidadeModulo;
use App\Models\Gabinete;
use App\Models\PlanoComercialVersao;
use App\Models\User;
use App\Services\Entidades\EntidadeQuotaService;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class EntidadeLicensingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_entidade_receives_complete_legacy_compatible_entitlements(): void
    {
        $office = Gabinete::factory()->create();

        $this->assertCount(
            count(EntidadeModule::cases()),
            app(EntidadeModuleManager::class)->activeFor($office->entidade_id),
        );
        $this->assertDatabaseHas('entidade_licencas', [
            'entidade_id' => $office->entidade_id,
            'status' => LicenseStatus::Active->value,
        ]);
    }

    public function test_entidade_module_gate_suspends_unit_module_without_deleting_its_configuration(): void
    {
        $office = Gabinete::factory()->create();
        $setting = EntidadeModulo::query()
            ->where('entidade_id', $office->entidade_id)
            ->where('modulo', EntidadeModule::Demands)
            ->firstOrFail();
        $setting->forceFill(['ativo' => false, 'desativado_em' => now()])->save();

        $this->assertFalse(app(GabineteModuleManager::class)->isActive($office, GabineteModule::Demands));
        $this->assertDatabaseHas('gabinete_modulos', [
            'gabinete_id' => $office->id,
            'modulo' => GabineteModule::Demands->value,
            'ativo' => true,
        ]);
    }

    public function test_expired_license_blocks_new_actions_but_does_not_delete_or_hide_existing_data(): void
    {
        $office = Gabinete::factory()->create();
        $license = $office->entidade->licencas()->firstOrFail();
        $license->forceFill(['fim_em' => now()->subMinute()])->save();

        $this->expectException(ValidationException::class);
        app(EntidadeQuotaService::class)->assertNewActionsAllowed($office->entidade_id);

        $this->assertDatabaseHas('entidades', ['id' => $office->entidade_id]);
        $this->assertDatabaseHas('gabinetes', ['id' => $office->id]);
    }

    public function test_blocking_quota_rejects_only_the_next_related_action(): void
    {
        $office = Gabinete::factory()->create();
        $license = $office->entidade->licencas()->firstOrFail();
        $quotas = $license->cotas_snapshot;
        $quotas[EntidadeQuota::ActiveGabinetes->value] = 1;
        $license->forceFill(['cotas_snapshot' => $quotas])->save();

        try {
            app(EntidadeQuotaService::class)->assertAvailable(
                $office->entidade_id,
                EntidadeQuota::ActiveGabinetes,
            );
            $this->fail('A criação acima da cota deveria ter sido bloqueada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quota', $exception->errors());
        }

        $this->assertNotNull(Gabinete::withoutGlobalScopes()->find($office->id));
    }

    public function test_published_plan_version_is_immutable(): void
    {
        $version = PlanoComercialVersao::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $version->forceFill(['versao' => 2])->save();
    }

    public function test_platform_admin_can_change_entidade_activation_with_audit(): void
    {
        $office = Gabinete::factory()->create();
        $admin = User::factory()->root()->create();
        $manager = app(EntidadeModuleManager::class);
        $selection = array_values(array_filter(
            array_column(EntidadeModule::cases(), 'value'),
            fn (string $module): bool => $module !== EntidadeModule::Reports->value,
        ));

        $manager->syncActivation($office->entidade, $selection, $admin);

        $this->assertFalse($manager->isActive(
            $office->entidade_id,
            EntidadeModule::Reports,
        ));
        $this->assertDatabaseHas('entidade_modulo_eventos', [
            'entidade_id' => $office->entidade_id,
            'modulo' => EntidadeModule::Reports->value,
            'acao' => 'DESATIVADO',
            'administrador_id' => $admin->id,
        ]);
    }
}
