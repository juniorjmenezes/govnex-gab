<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_authenticate(): void
    {
        // Root: desde o corte para o SSO do Hub, o login por senha só aceita
        // root (docs/INTEGRACAO_GOVNEX_HUB.md, decisões #2 e #6). Com uma conta
        // comum, o teste passaria pelo motivo errado — o papel, não o
        // `is_active` — e deixaria de cobrir o que se propõe.
        $user = User::factory()->root()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    /**
     * Um gabinete suspenso tira o acesso àquele gabinete, não à conta: a pessoa
     * segue autenticada e é levada à escolha de entidade. Antes do SSO isso se
     * verificava fazendo login por senha; agora a sessão de uma conta comum
     * nasce no callback do Hub (`HubAuthController`), então o que resta aqui é
     * a metade que não mudou — o que acontece depois de autenticada.
     */
    public function test_suspended_primary_gabinete_does_not_disable_the_global_account(): void
    {
        $gabinete = Gabinete::factory()->suspended()->create();
        $user = User::factory()->forGabinete($gabinete)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_entity_member_without_primary_gabinete_keeps_access(): void
    {
        $entidade = Entidade::factory()->create();
        $user = User::factory()->create(['gabinete_id' => null]);
        EntidadeMembro::query()->create([
            'entidade_id' => $entidade->id,
            'usuario_id' => $user->id,
            'papel' => AccessRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_authenticated_user_is_logged_out(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_platform_admin_can_access_without_gabinete(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
