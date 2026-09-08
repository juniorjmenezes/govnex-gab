<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntidadeRole;
use App\Enums\GabineteRole;
use App\Enums\GabineteType;
use App\Models\Cidadao;
use App\Models\ContextoAcessoEvento;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntidadeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_legacy_office_and_users_receive_compatible_entidade_memberships(): void
    {
        $office = Gabinete::factory()->create();
        $councilor = User::factory()->councilor()->forGabinete($office)->create();
        $chief = User::factory()->chiefOfStaff()->forGabinete($office)->create();
        $advisor = User::factory()->advisor()->forGabinete($office)->create();

        $this->assertNotNull($office->entidade_id);
        $this->assertSame(GabineteType::IndependentOffice, $office->tipo_gabinete);

        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $office->entidade_id,
            'usuario_id' => $councilor->id,
            'papel' => EntidadeRole::Administrator->value,
            'ativo' => true,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $office->id,
            'usuario_id' => $chief->id,
            'papel' => GabineteRole::Manager->value,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $office->id,
            'usuario_id' => $advisor->id,
            'papel' => GabineteRole::Member->value,
        ]);
        $this->assertDatabaseHas('gabinete_liderancas', [
            'gabinete_id' => $office->id,
            'usuario_id' => $councilor->id,
            'fim_em' => null,
        ]);
    }

    public function test_explicit_unit_context_isolates_records_for_multi_unit_user(): void
    {
        $firstUnit = Gabinete::factory()->create();
        $entidade = $firstUnit->entidade;
        $secondUnit = Gabinete::factory()->for($entidade, 'entidade')->create([
            'tipo_gabinete' => GabineteType::AdministrativeDepartment,
        ]);
        $user = User::factory()->advisor()->forGabinete($firstUnit)->create();

        GabineteMembro::query()->create([
            'gabinete_id' => $secondUnit->id,
            'usuario_id' => $user->id,
            'papel' => GabineteRole::Member,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $firstCitizen = Cidadao::factory()->forGabinete($firstUnit)->create();
        $secondCitizen = Cidadao::factory()->forGabinete($secondUnit)->create();

        $this->assertSame($entidade->id, $secondUnit->entidade_id);
        $this->assertTrue($user->canAccessEntidade($entidade->id));
        $this->assertTrue($user->canAccessGabinete($secondUnit->id));

        $response = $this->actingAs($user)
            ->get(route('context.citizens.index', [
                'entidade' => $entidade,
                'gabinete' => $secondUnit,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('citizens/index')
                ->has('citizens.data', 1)
                ->where('citizens.data.0.id', $secondCitizen->id)
                ->where('auth.context.entidade.id', $entidade->id)
                ->where('auth.context.gabinete.id', $secondUnit->id));

        $this->assertDatabaseHas('contexto_acesso_eventos', [
            'entidade_id' => $entidade->id,
            'gabinete_id' => $secondUnit->id,
            'usuario_id' => $user->id,
            'resultado' => 'PERMITIDO',
        ]);
        $this->assertNotSame($firstCitizen->id, $secondCitizen->id);
    }

    public function test_platform_admin_is_scoped_after_entering_an_explicit_unit(): void
    {
        $firstUnit = Gabinete::factory()->create();
        $secondUnit = Gabinete::factory()->create();
        $admin = User::factory()->root()->create();
        Cidadao::factory()->forGabinete($firstUnit)->create();
        $expected = Cidadao::factory()->forGabinete($secondUnit)->create();

        $this->actingAs($admin)
            ->get(route('context.citizens.index', [
                'entidade' => $secondUnit->entidade,
                'gabinete' => $secondUnit,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('citizens.data', 1)
                ->where('citizens.data.0.id', $expected->id));

        $this->assertTrue(ContextoAcessoEvento::query()
            ->where('usuario_id', $admin->id)
            ->where('administrador_plataforma', true)
            ->exists());
    }

    public function test_user_cannot_enter_an_unrelated_entidade_or_unit(): void
    {
        $allowed = Gabinete::factory()->create();
        $blocked = Gabinete::factory()->create();
        $user = User::factory()->advisor()->forGabinete($allowed)->create();

        $this->actingAs($user)
            ->get(route('context.citizens.index', [
                'entidade' => $blocked->entidade,
                'gabinete' => $blocked,
            ]))
            ->assertForbidden();

        $this->assertDatabaseMissing('contexto_acesso_eventos', [
            'usuario_id' => $user->id,
            'entidade_id' => $blocked->entidade_id,
        ]);
    }

    public function test_deactivated_membership_blocks_context_without_deleting_data(): void
    {
        $office = Gabinete::factory()->create();
        $user = User::factory()->advisor()->forGabinete($office)->create();
        $citizen = Cidadao::factory()->forGabinete($office)->create();

        EntidadeMembro::query()
            ->where('usuario_id', $user->id)
            ->update(['ativo' => false, 'desativado_em' => now()]);

        $this->actingAs($user)
            ->get(route('context.citizens.index', [
                'entidade' => $office->entidade,
                'gabinete' => $office,
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('cidadaos', ['id' => $citizen->id]);
    }

    public function test_entity_page_does_not_inherit_primary_office_from_another_entidade(): void
    {
        $primaryOffice = Gabinete::factory()->create();
        $otherEntidade = Gabinete::factory()->create()->entidade;
        $user = User::factory()->advisor()->forGabinete($primaryOffice)->create();
        EntidadeMembro::query()->create([
            'entidade_id' => $otherEntidade->id,
            'usuario_id' => $user->id,
            'papel' => EntidadeRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('entidades.show', $otherEntidade))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.context.entidade.id', $otherEntidade->id)
                ->where('auth.context.gabinete', null)
                ->where('auth.context.gabinete_base_url', null));
    }

    public function test_entity_page_keeps_the_users_own_gabinete_in_context(): void
    {
        $office = Gabinete::factory()->create();
        $chief = User::factory()->chiefOfStaff()->forGabinete($office)->create();

        $this->actingAs($chief)
            ->get(route('entidades.show', $office->entidade))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.context.entidade.id', $office->entidade_id)
                ->where('auth.context.gabinete.id', $office->id)
                ->where(
                    'auth.context.gabinete_base_url',
                    "/entidades/{$office->entidade->slug}/gabinetes/{$office->slug}",
                ));
    }
}
