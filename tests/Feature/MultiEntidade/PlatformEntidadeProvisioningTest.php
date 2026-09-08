<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntidadeRole;
use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteRole;
use App\Enums\GabineteType;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformEntidadeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 2304400, 'nome' => 'Fortaleza'],
                ['id' => 2312908, 'nome' => 'Sobral'],
                ['id' => 2314102, 'nome' => 'Viçosa do Ceará'],
            ]),
        ]);
    }

    public function test_platform_admin_creates_an_entidade_before_its_first_office(): void
    {
        $admin = User::factory()->root()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.entities.store'), $this->entidadePayload());

        $entidade = Entidade::query()->where('nome', 'Gabinete Horizonte')->firstOrFail();
        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offices.create', ['entidade' => $entidade->id]));
        $this->assertDatabaseCount('gabinetes', 0);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::IndependentOffice->value,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offices.index'));

        $gabinete = Gabinete::withoutGlobalScopes()->where('nome', 'Gabinete Horizonte')->firstOrFail();
        $leader = User::query()->where('email', 'lider@horizonte.test')->firstOrFail();

        $this->assertSame(EntidadeType::IndependentOffice, $entidade->tipo);
        $this->assertSame(GabineteType::IndependentOffice, $gabinete->tipo_gabinete);
        $this->assertTrue($entidade->interface_simplificada);
        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $entidade->id,
            'usuario_id' => $leader->id,
            'papel' => EntidadeRole::Administrator->value,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $gabinete->id,
            'usuario_id' => $leader->id,
            'papel' => GabineteRole::Leader->value,
        ]);
    }

    public function test_entity_creation_form_is_separate_and_restricted_to_platform_admin(): void
    {
        $admin = User::factory()->root()->create();
        $tenantUser = User::factory()->create();

        $this->actingAs($tenantUser)
            ->get(route('admin.entities.create'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.entities.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/entities/form')
                ->has('types', 3));
    }

    public function test_platform_admin_creates_a_city_council_with_a_councilor_office(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'nome' => 'Câmara Municipal de Horizonte',
            'interface_simplificada' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::CouncilorOffice->value,
                'nome' => 'Gabinete Parlamentar 01',
            ]))
            ->assertSessionHasNoErrors();

        $gabinete = $entidade->gabinetes()->withoutGlobalScopes()->firstOrFail();
        $leader = User::query()->where('email', 'lider@horizonte.test')->firstOrFail();

        $this->assertSame(EntidadeType::CityCouncil, $entidade->tipo);
        $this->assertSame(GabineteType::CouncilorOffice, $gabinete->tipo_gabinete);
        $this->assertFalse($entidade->interface_simplificada);
        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $entidade->id,
            'usuario_id' => $leader->id,
            'papel' => EntidadeRole::Operator->value,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $gabinete->id,
            'usuario_id' => $leader->id,
            'papel' => GabineteRole::Leader->value,
        ]);
    }

    public function test_platform_admin_creates_a_city_council_with_an_administrative_unit(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'nome' => 'Câmara Municipal de Viçosa do Ceará',
            'municipio' => 'Viçosa do Ceará',
            'estado' => 'CE',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::AdministrativeDepartment->value,
                'nome' => 'Administrativo',
                'vereador_nome' => 'Francisco José Alves de Arruda',
                'municipio' => 'Viçosa do Ceará',
                'estado' => 'CE',
                'telefone' => '(88) 9.8230-4959',
                'email' => 'camaravicosa@outlook.com',
                'endereco' => 'Av. Mj. Felizardo de Pinho Pessoa',
                'numero' => '90',
                'complemento' => 'Completo',
                'bairro' => 'Centro',
                'cep' => '62300-000',
                'responsavel_nome' => 'Funcionário de Teste',
                'responsavel_email' => 'cmvcteste@gmail.com',
                'responsavel_password' => 'Cadastro!GovnexGab2026',
                'responsavel_password_confirmation' => 'Cadastro!GovnexGab2026',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offices.index'));

        $gabinete = $entidade->gabinetes()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(EntidadeType::CityCouncil, $entidade->tipo);
        $this->assertSame(GabineteType::AdministrativeDepartment, $gabinete->tipo_gabinete);
        $this->assertSame('Viçosa do Ceará', $gabinete->municipio);
        $this->assertDatabaseHas('users', [
            'gabinete_id' => $gabinete->id,
            'name' => 'Funcionário de Teste',
            'email' => 'cmvcteste@gmail.com',
        ]);
    }

    public function test_initial_password_policy_rejects_a_numeric_password(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::IndependentOffice->value,
                'responsavel_password' => '1234567890',
                'responsavel_password_confirmation' => '1234567890',
            ]))
            ->assertSessionHasErrors('responsavel_password');

        $this->assertDatabaseCount('gabinetes', 0);
    }

    public function test_platform_admin_adds_a_department_to_an_existing_council(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'nome' => 'Câmara Municipal de Fortaleza',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'timezone' => 'America/Fortaleza',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::AdministrativeDepartment->value,
                'nome' => 'Diretoria Legislativa',
                'numero_eleitoral' => '999',
            ]))
            ->assertSessionHasNoErrors();

        $gabinete = $entidade->gabinetes()->withoutGlobalScopes()->where('nome', 'Diretoria Legislativa')->firstOrFail();

        $this->assertDatabaseCount('entidades', 1);
        $this->assertSame(GabineteType::AdministrativeDepartment, $gabinete->tipo_gabinete);
        $this->assertSame('Fortaleza', $gabinete->municipio);
        $this->assertSame('CE', $gabinete->estado);
        $this->assertSame('America/Fortaleza', $gabinete->timezone);
        $this->assertNull($gabinete->numero_eleitoral);
    }

    public function test_platform_admin_creates_a_city_hall_with_a_secretariat(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityHall,
            'nome' => 'Prefeitura de Horizonte',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::Secretariat->value,
                'nome' => 'Secretaria de Educação',
            ]))
            ->assertSessionHasNoErrors();

        $gabinete = $entidade->gabinetes()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(EntidadeType::CityHall, $entidade->tipo);
        $this->assertSame(GabineteType::Secretariat, $gabinete->tipo_gabinete);
        $this->assertSame('Secretário', $gabinete->tipo_gabinete->leaderLabel());
    }

    public function test_incompatible_unit_type_is_rejected(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create(['tipo' => EntidadeType::CityCouncil]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $entidade->id,
                'tipo_gabinete' => GabineteType::Secretariat->value,
            ]))
            ->assertSessionHasErrors('tipo_gabinete');

        $this->assertDatabaseCount('gabinetes', 0);
    }

    public function test_independent_entidade_rejects_a_second_office_and_location_is_inherited(): void
    {
        $admin = User::factory()->root()->create();
        $independent = Entidade::factory()->create([
            'tipo' => EntidadeType::IndependentOffice,
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
        ]);
        Gabinete::factory()->for($independent, 'entidade')->create([
            'tipo_gabinete' => GabineteType::IndependentOffice,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $independent->id,
                'tipo_gabinete' => GabineteType::IndependentOffice->value,
            ]))
            ->assertSessionHasErrors('entidade_id');

        $council = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'municipio' => 'Sobral',
            'estado' => 'CE',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offices.store'), $this->payload([
                'entidade_id' => $council->id,
                'tipo_gabinete' => GabineteType::CouncilorOffice->value,
            ]))
            ->assertSessionHasNoErrors();

        $created = $council->gabinetes()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Sobral', $created->municipio);
        $this->assertSame('CE', $created->estado);
    }

    public function test_contextual_entidade_role_is_not_downgraded_by_legacy_user_updates(): void
    {
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
        ]);
        $gabinete = Gabinete::factory()->for($entidade, 'entidade')->create([
            'tipo_gabinete' => GabineteType::CouncilorOffice,
        ]);
        $leader = User::factory()->councilor()->forGabinete($gabinete)->create();
        $membership = EntidadeMembro::query()
            ->where('entidade_id', $entidade->id)
            ->where('usuario_id', $leader->id)
            ->firstOrFail();
        $membership->forceFill(['papel' => EntidadeRole::Administrator])->save();

        $leader->forceFill(['name' => 'Liderança Atualizada'])->save();

        $this->assertDatabaseHas('entidade_membros', [
            'id' => $membership->id,
            'papel' => EntidadeRole::Administrator->value,
        ]);
    }

    public function test_create_form_lists_active_entidades_that_can_receive_an_office(): void
    {
        $admin = User::factory()->root()->create();
        Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'nome' => 'Câmara Ativa',
        ]);
        Entidade::factory()->create([
            'tipo' => EntidadeType::IndependentOffice,
            'nome' => 'Gabinete Isolado',
        ]);
        Entidade::factory()->create([
            'tipo' => EntidadeType::CityHall,
            'nome' => 'Prefeitura Suspensa',
            'status' => EntidadeStatus::Suspended,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.offices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/offices/form')
                ->has('entidades', 2)
                ->where('entidades.0.name', 'Câmara Ativa')
                ->where('entidades.1.name', 'Gabinete Isolado'));
    }

    public function test_office_creation_form_preselects_the_entity_from_the_url(): void
    {
        $admin = User::factory()->root()->create();
        $entidade = Entidade::factory()->create([
            'tipo' => EntidadeType::CityCouncil,
            'nome' => 'Câmara Selecionada',
            'interface_simplificada' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.offices.create', ['entidade' => $entidade->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/offices/form')
                ->where('entidade.id', $entidade->id)
                ->where('entidade.name', 'Câmara Selecionada'));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'nome' => 'Gabinete Horizonte',
            'vereador_nome' => 'Marina Oliveira',
            'numero_eleitoral' => '12345',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'timezone' => 'America/Fortaleza',
            'telefone' => '(85) 99999-0000',
            'email' => 'contato@horizonte.test',
            'endereco' => 'Rua da Cidadania, 100',
            'responsavel_nome' => 'Marina Oliveira',
            'responsavel_email' => 'lider@horizonte.test',
            'responsavel_password' => 'Senha!Segura2026',
            'responsavel_password_confirmation' => 'Senha!Segura2026',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function entidadePayload(array $overrides = []): array
    {
        return [
            'tipo' => EntidadeType::IndependentOffice->value,
            'nome' => 'Gabinete Horizonte',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'timezone' => 'America/Fortaleza',
            ...$overrides,
        ];
    }
}
