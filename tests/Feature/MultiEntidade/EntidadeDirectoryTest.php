<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntidadeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.ssr.enabled' => false]);
    }

    public function test_platform_admin_can_search_and_paginate_entidade_cards(): void
    {
        $admin = User::factory()->root()->create();

        foreach (range(1, 9) as $index) {
            Entidade::factory()->create(['nome' => sprintf('Entidade %02d', $index)]);
        }

        $target = Entidade::factory()->create([
            'nome' => 'Câmara Municipal de Aurora',
            'municipio' => 'Aurora',
            'estado' => 'CE',
        ]);
        Gabinete::factory()->for($target, 'entidade')->create([
            'nome' => 'Gabinete da Presidência',
        ]);

        $this->actingAs($admin)
            ->get(route('entidades.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('entities/index')
                ->has('entidades.data', 8)
                ->where('entidades.current_page', 1)
                ->where('entidades.total', 10)
                ->where('filters.q', ''));

        $this->actingAs($admin)
            ->get(route('entidades.index', ['q' => 'Presidência']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('entities/index')
                ->has('entidades.data', 1)
                ->where('entidades.data.0.name', 'Câmara Municipal de Aurora')
                ->where('entidades.data.0.gabinetes.0.name', 'Gabinete da Presidência')
                ->where('filters.q', 'Presidência'));
    }

    public function test_directory_search_does_not_expose_an_unrelated_entidade(): void
    {
        $allowedOffice = Gabinete::factory()->create();
        $blockedOffice = Gabinete::factory()
            ->for($allowedOffice->entidade, 'entidade')
            ->create(['nome' => 'Gabinete Confidencial']);
        $user = User::factory()->operator()->forGabinete($allowedOffice)->create();

        $this->actingAs($user)
            ->get(route('entidades.index', ['q' => 'Confidencial']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('entities/index')
                ->has('entidades.data', 0)
                ->where('entidades.total', 0));

        $this->assertTrue($user->canAccessEntidade($blockedOffice->entidade_id));
        $this->assertFalse($user->canAccessGabinete($blockedOffice->id));
    }

    public function test_entity_member_without_a_primary_office_can_open_the_directory(): void
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
            ->get(route('entidades.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('entities/index')
                ->has('entidades.data', 1)
                ->where('entidades.data.0.id', $entidade->id));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('entidades.index'));
    }

    public function test_platform_admin_sees_the_office_dashboard_inside_explicit_context(): void
    {
        $admin = User::factory()->root()->create();
        $office = Gabinete::factory()->create();

        $this->actingAs($admin)
            ->get(route('context.dashboard', [
                'entidade' => $office->entidade,
                'gabinete' => $office,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('auth.context.entidade.id', $office->entidade_id)
                ->where('auth.context.gabinete.id', $office->id));
    }
}
