<?php

namespace Tests\Feature\Admin;

use App\Models\Gabinete;
use App\Models\PartidoCor;
use App\Models\User;
use Database\Seeders\PartidoCorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartyColorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_root_can_list_create_update_and_delete_party_colors(): void
    {
        $root = User::factory()->root()->create();

        $this->actingAs($root)
            ->get(route('admin.party-colors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/parties/index')
                ->where('parties', []));

        $this->actingAs($root)
            ->post(route('admin.party-colors.store'), [
                'sigla' => 'psl',
                'cor' => '#123abc',
            ])
            ->assertRedirect();

        $partidoCor = PartidoCor::query()->where('sigla', 'PSL')->firstOrFail();
        $this->assertSame('#123ABC', $partidoCor->cor);

        $this->actingAs($root)
            ->patch(route('admin.party-colors.update', $partidoCor), [
                'sigla' => 'psl',
                'cor' => '#ffffff',
            ])
            ->assertRedirect();
        $this->assertSame('#FFFFFF', $partidoCor->refresh()->cor);

        $this->actingAs($root)
            ->delete(route('admin.party-colors.destroy', $partidoCor))
            ->assertRedirect();
        $this->assertNull(PartidoCor::query()->find($partidoCor->id));
    }

    public function test_party_color_rejects_duplicate_sigla_and_invalid_hex(): void
    {
        $root = User::factory()->root()->create();
        PartidoCor::query()->create(['sigla' => 'PT', 'cor' => '#FF0000']);

        $this->actingAs($root)
            ->post(route('admin.party-colors.store'), [
                'sigla' => 'pt',
                'cor' => '#00ff00',
            ])
            ->assertSessionHasErrors('sigla');

        $this->actingAs($root)
            ->post(route('admin.party-colors.store'), [
                'sigla' => 'PSDB',
                'cor' => 'not-a-color',
            ])
            ->assertSessionHasErrors('cor');
    }

    public function test_party_color_rejects_duplicate_sigla_ignoring_accents(): void
    {
        $root = User::factory()->root()->create();
        $missao = PartidoCor::query()->create(['sigla' => 'MISSÃO', 'cor' => '#FFD600']);

        $this->actingAs($root)
            ->post(route('admin.party-colors.store'), [
                'sigla' => 'MISSAO',
                'cor' => '#000000',
            ])
            ->assertSessionHasErrors('sigla');

        $outro = PartidoCor::query()->create(['sigla' => 'PT', 'cor' => '#E30613']);
        $this->actingAs($root)
            ->patch(route('admin.party-colors.update', $outro), [
                'sigla' => 'MISSAO',
                'cor' => '#E30613',
            ])
            ->assertSessionHasErrors('sigla');

        // A própria sigla (sem alteração de acento) continua permitida.
        $this->actingAs($root)
            ->patch(route('admin.party-colors.update', $missao), [
                'sigla' => 'MISSÃO',
                'cor' => '#FFD600',
            ])
            ->assertSessionDoesntHaveErrors('sigla');
    }

    public function test_non_root_users_cannot_access_party_color_management(): void
    {
        $gabinete = Gabinete::factory()->create();
        $councilor = User::factory()->administrator()->forGabinete($gabinete)->create();
        $partidoCor = PartidoCor::query()->create(['sigla' => 'PT', 'cor' => '#FF0000']);

        $this->actingAs($councilor)->get(route('admin.party-colors.index'))->assertForbidden();
        $this->actingAs($councilor)->post(route('admin.party-colors.store'), [
            'sigla' => 'PL',
            'cor' => '#000000',
        ])->assertForbidden();
        $this->actingAs($councilor)
            ->patch(route('admin.party-colors.update', $partidoCor), [
                'sigla' => 'PT',
                'cor' => '#000000',
            ])
            ->assertForbidden();
        $this->actingAs($councilor)
            ->delete(route('admin.party-colors.destroy', $partidoCor))
            ->assertForbidden();
    }

    public function test_reapplying_the_party_seeder_preserves_an_administrator_color(): void
    {
        PartidoCor::query()->create(['sigla' => 'PT', 'cor' => '#123456']);

        $this->seed(PartidoCorSeeder::class);

        $this->assertSame('#123456', PartidoCor::query()->where('sigla', 'PT')->value('cor'));
        $this->assertDatabaseHas('partido_cores', ['sigla' => 'PL']);
    }
}
