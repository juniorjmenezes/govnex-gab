<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyTenantRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_user_enters_the_explicit_context_from_legacy_dashboard(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->withoutFollowingLegacyTenantRedirects();
        $user = User::query()->where('email', 'vereador@gabinetefacil.test')->firstOrFail();
        $gabinete = Gabinete::withoutGlobalScopes()->findOrFail($user->gabinete_id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect("/entidades/{$gabinete->entidade->slug}/gabinetes/{$gabinete->slug}/dashboard");
    }

    public function test_legacy_read_redirects_to_explicit_context_and_preserves_query_string(): void
    {
        $this->withoutFollowingLegacyTenantRedirects();
        $gabinete = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($gabinete)->create();
        $expected = "/entidades/{$gabinete->entidade->slug}/gabinetes/{$gabinete->slug}/cidadaos?q=Maria";

        $this->actingAs($user)
            ->get('/cidadaos?q=Maria')
            ->assertRedirect($expected)
            ->assertStatus(302)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('contexto_acesso_eventos', [
            'entidade_id' => $gabinete->entidade_id,
            'gabinete_id' => $gabinete->id,
            'usuario_id' => $user->id,
            'evento' => 'ROTA_LEGADA_REDIRECIONADA',
            'resultado' => 'LEITURA',
        ]);
    }

    public function test_legacy_write_uses_method_preserving_redirect_before_business_processing(): void
    {
        $this->withoutFollowingLegacyTenantRedirects();
        $gabinete = Gabinete::factory()->create();
        $user = User::factory()->administrator()->forGabinete($gabinete)->create();
        $expected = "/entidades/{$gabinete->entidade->slug}/gabinetes/{$gabinete->slug}/bairros";

        $this->actingAs($user)
            ->post('/bairros', ['nome' => 'Centro', 'ativo' => true])
            ->assertStatus(307)
            ->assertRedirect($expected);

        $this->assertDatabaseMissing('bairros', ['nome' => 'Centro']);
        $this->assertDatabaseHas('contexto_acesso_eventos', [
            'entidade_id' => $gabinete->entidade_id,
            'gabinete_id' => $gabinete->id,
            'usuario_id' => $user->id,
            'evento' => 'ROTA_LEGADA_REDIRECIONADA',
            'resultado' => 'ESCRITA',
        ]);
    }

    public function test_explicit_context_does_not_create_legacy_route_event(): void
    {
        $gabinete = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($gabinete)->create();

        $this->actingAs($user)
            ->get(route('context.citizens.index', [
                'entidade' => $gabinete->entidade,
                'gabinete' => $gabinete,
            ]))
            ->assertOk();

        $this->assertDatabaseMissing('contexto_acesso_eventos', [
            'usuario_id' => $user->id,
            'evento' => 'ROTA_LEGADA_REDIRECIONADA',
        ]);
    }

    public function test_legacy_link_preserves_secondary_unit_from_same_origin_referer(): void
    {
        $this->withoutFollowingLegacyTenantRedirects();
        $primary = Gabinete::factory()->create();
        $secondary = Gabinete::factory()->for($primary->entidade, 'entidade')->create();
        $user = User::factory()->operator()->forGabinete($primary)->create();
        GabineteMembro::query()->create([
            'gabinete_id' => $secondary->id,
            'usuario_id' => $user->id,
            'papel' => AccessRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);
        $referer = route('context.dashboard', [
            'entidade' => $primary->entidade,
            'gabinete' => $secondary,
        ]);
        $expected = "/entidades/{$primary->entidade->slug}/gabinetes/{$secondary->slug}/cidadaos";

        $this->actingAs($user)
            ->withHeader('Referer', $referer)
            ->get('/cidadaos')
            ->assertStatus(302)
            ->assertRedirect($expected);
    }

    public function test_platform_admin_legacy_link_uses_explicit_referer_context(): void
    {
        $this->withoutFollowingLegacyTenantRedirects();
        $office = Gabinete::factory()->create();
        $admin = User::factory()->root()->create();
        $referer = route('context.dashboard', [
            'entidade' => $office->entidade,
            'gabinete' => $office,
        ]);
        $expected = "/entidades/{$office->entidade->slug}/gabinetes/{$office->slug}/demandas";

        $this->actingAs($admin)
            ->withServerVariables([
                'HTTP_HOST' => parse_url($referer, PHP_URL_HOST),
                'SERVER_NAME' => parse_url($referer, PHP_URL_HOST),
            ])
            ->withHeader('Referer', $referer)
            ->get('/demandas')
            ->assertStatus(302)
            ->assertRedirect($expected);
    }
}
