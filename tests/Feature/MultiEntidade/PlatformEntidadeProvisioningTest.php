<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Enums\EntidadeType;
use App\Enums\GabineteType;
use App\Http\Controllers\Admin\EstruturaNoHubController;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Entidade e gabinete nascem no Govnex Hub (webhook `*.criada`, ver
 * `tests/Feature/Hub`). A criação local pela administração foi desligada: as
 * rotas antigas só respondem com a orientação.
 */
class PlatformEntidadeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_local_creation_forms_redirect_with_the_hub_notice(): void
    {
        $admin = User::factory()->root()->create();

        foreach (['admin.entities.create', 'admin.offices.create'] as $route) {
            $this->actingAs($admin)
                ->get(route($route))
                ->assertRedirect(route('admin.offices.index'))
                ->assertInertiaFlash('toast.message', EstruturaNoHubController::MENSAGEM.' Entidades e gabinetes nascem aqui automaticamente.');
        }
    }

    public function test_local_creation_is_refused_and_creates_nothing(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create(['tipo' => EntidadeType::CityCouncil]);
        $usersBefore = User::query()->count();

        $this->actingAs($admin)
            ->post(route('admin.entities.store'), [
                'tipo' => EntidadeType::IndependentOffice->value,
                'nome' => 'Gabinete Horizonte',
                'municipio' => 'Fortaleza',
                'estado' => 'CE',
                'timezone' => 'America/Fortaleza',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), [
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::CouncilorOffice->value,
                'nome' => 'Gabinete Parlamentar 01',
                'vereador_nome' => 'Marina Oliveira',
                'responsavel_nome' => 'Marina Oliveira',
                'responsavel_email' => 'lider@horizonte.test',
                'responsavel_password' => 'Senha!Segura2026',
                'responsavel_password_confirmation' => 'Senha!Segura2026',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('entidades', 1);
        $this->assertDatabaseCount('gabinetes', 0);
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_creation_routes_stay_restricted_to_platform_admin(): void
    {
        $tenantUser = User::factory()->create();

        $this->actingAs($tenantUser)
            ->get(route('admin.entities.create'))
            ->assertForbidden();
    }

    public function test_admin_pages_point_to_the_hub_instead_of_local_creation(): void
    {
        config(['services.hub.base_url' => 'https://hub.teste']);
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get(route('admin.offices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hubStructureUrl', 'https://hub.teste/estrutura'));

        $tenantUser = User::factory()->create();

        $this->actingAs($tenantUser)
            ->get(route('entidades.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hubStructureUrl', null));
    }

    public function test_contextual_entidade_role_is_not_downgraded_by_legacy_user_updates(): void
    {
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
        ]);
        $gabinete = Gabinete::factory()->for($entidade, 'entidade')->create([
            'tipo_gabinete' => GabineteType::CouncilorOffice,
        ]);
        $leader = User::factory()->administrator()->forGabinete($gabinete)->create();
        $membership = EntidadeMembro::query()
            ->where('entidade_id', $entidade->id)
            ->where('usuario_id', $leader->id)
            ->firstOrFail();
        $membership->forceFill(['papel' => AccessRole::Administrator])->save();

        $leader->forceFill(['name' => 'Liderança Atualizada'])->save();

        $this->assertDatabaseHas('entidade_membros', [
            'id' => $membership->id,
            'papel' => AccessRole::Administrator->value,
        ]);
    }
}
