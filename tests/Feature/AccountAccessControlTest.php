<?php

namespace Tests\Feature;

use App\Enums\EntidadeRole;
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
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_suspended_primary_gabinete_does_not_disable_the_global_account(): void
    {
        $gabinete = Gabinete::factory()->suspended()->create();
        $user = User::factory()->forGabinete($gabinete)->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $this->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_entity_member_without_primary_gabinete_can_authenticate(): void
    {
        $entidade = Entidade::factory()->create();
        $user = User::factory()->create(['gabinete_id' => null]);
        EntidadeMembro::query()->create([
            'entidade_id' => $entidade->id,
            'usuario_id' => $user->id,
            'papel' => EntidadeRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));
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
