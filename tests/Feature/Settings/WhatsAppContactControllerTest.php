<?php

namespace Tests\Feature\Settings;

use App\Enums\AccessRole;
use App\Enums\GabineteModule;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppContactControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sem `WHATSAPP_PHONE_HASH_SECRET` configurado,
     * `WhatsAppContactService::declareForUser()` lança `RuntimeException`.
     * Antes da correção, o controller não capturava isso e o clique em
     * "Cadastrar WhatsApp" nas configurações do perfil quebrava com 500.
     */
    public function test_declaring_whatsapp_consent_flashes_a_friendly_error_when_hash_secret_is_missing(): void
    {
        config(['whatsapp.phone_hash_secret' => null]);
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        app(GabineteModuleManager::class)->sync($office, [
            GabineteModule::Relationship->value,
            GabineteModule::Schedule->value,
            GabineteModule::WhatsApp->value,
        ], $user);

        $this->actingAs($user)
            ->post(route('whatsapp-contact.store'), [
                'telefone' => '(85) 99999-1111',
                'aceite' => true,
            ])
            ->assertRedirect();

        $flash = session('inertia.flash_data', []);
        $this->assertSame('error', $flash['toast']['type'] ?? null);
    }

    /**
     * Pausado pelo Time (`gabinete_membros.ativo = false`), não pode
     * autodeclarar o próprio WhatsApp: `users.is_active` continua `true` —
     * só o vínculo de equipe muda — então só esse gate evita o acesso.
     */
    public function test_a_team_member_paused_in_the_team_screen_cannot_self_register_whatsapp(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        app(GabineteModuleManager::class)->sync($office, [
            GabineteModule::Relationship->value,
            GabineteModule::Schedule->value,
            GabineteModule::WhatsApp->value,
        ], $user);
        GabineteMembro::query()->updateOrCreate(
            ['gabinete_id' => $office->id, 'usuario_id' => $user->id],
            ['papel' => AccessRole::Operator, 'ativo' => false, 'ingressou_em' => now(), 'desativado_em' => now()],
        );

        $this->actingAs($user)
            ->post(route('whatsapp-contact.store'), [
                'telefone' => '(85) 99999-1111',
                'aceite' => true,
            ])
            ->assertForbidden();
    }
}
