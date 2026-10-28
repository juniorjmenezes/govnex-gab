<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntityModule;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntidadeModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_entidade_administrator_cannot_toggle_institutional_modules(): void
    {
        $gabinete = Office::factory()->create();
        $councilor = User::factory()->administrator()->forGabinete($gabinete)->create();

        $this->actingAs($councilor)
            ->patch(route('entidades.modules.update', $gabinete->entidade), [
                'modules' => array_column(EntityModule::cases(), 'value'),
            ])
            ->assertForbidden();
    }

    public function test_root_can_toggle_institutional_modules(): void
    {
        $gabinete = Office::factory()->create();
        $root = User::factory()->root()->create();
        $modules = array_column(EntityModule::availableCases($gabinete->entidade->tipo), 'value');

        $this->actingAs($root)
            ->patch(route('entidades.modules.update', $gabinete->entidade), [
                'modules' => $modules,
            ])
            ->assertRedirect();

        foreach ($modules as $module) {
            $this->assertDatabaseHas('entidade_modulos', [
                'entidade_id' => $gabinete->entidade_id,
                'modulo' => $module,
                'ativo' => true,
            ]);
        }
    }

    public function test_entidade_show_hides_module_section_from_non_root_but_keeps_other_management(): void
    {
        $gabinete = Office::factory()->create();
        $councilor = User::factory()->administrator()->forGabinete($gabinete)->create();

        $response = $this->actingAs($councilor)
            ->get(route('entidades.show', $gabinete->entidade));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canManage', true)
            ->where('canManageModules', false));
    }
}
