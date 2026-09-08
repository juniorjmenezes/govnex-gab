<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\GabineteRole;
use App\Models\Bairro;
use App\Models\EntidadeBairro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntidadeIdentityAndReferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_entidade_manager_updates_identity_and_logo_without_changing_unit_identity(): void
    {
        Storage::fake('public');
        $gabinete = Gabinete::factory()->create([
            'nome' => 'Gabinete parlamentar',
            'cor_principal' => '#334455',
        ]);
        $manager = User::factory()->councilor()->forGabinete($gabinete)->create();

        $this->actingAs($manager)
            ->post(route('entidades.identity.update', $gabinete->entidade), [
                'name' => 'Câmara Municipal de Teste',
                'timezone' => 'America/Fortaleza',
                'primary_color' => '#176B73',
                'secondary_color' => '#172026',
                'simplified_interface' => false,
                'logo' => UploadedFile::fake()->image('logo.png', 240, 120),
                'remove_logo' => false,
            ])
            ->assertRedirect();

        $entidade = $gabinete->entidade->refresh();
        $this->assertSame('Câmara Municipal de Teste', $entidade->nome);
        $this->assertSame('#176B73', $entidade->cor_principal);
        $this->assertFalse($entidade->interface_simplificada);
        $this->assertNotNull($entidade->logo_path);
        Storage::disk('public')->assertExists($entidade->logo_path);

        $this->assertSame('Gabinete parlamentar', $gabinete->refresh()->nome);
        $this->assertSame('#334455', $gabinete->cor_principal);

        $this->actingAs($manager)
            ->get(route('entidades.show', $entidade))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entidade.logo_url', Storage::disk('public')->url($entidade->logo_path))
                ->where('auth.context.entidade.primary_color', '#176B73'));
    }

    public function test_unprivileged_member_cannot_change_entidade_identity(): void
    {
        $gabinete = Gabinete::factory()->create();
        $member = User::factory()->advisor()->forGabinete($gabinete)->create();

        $this->actingAs($member)
            ->post(route('entidades.identity.update', $gabinete->entidade), [
                'name' => 'Nome indevido',
                'timezone' => 'America/Fortaleza',
                'primary_color' => null,
                'secondary_color' => null,
                'simplified_interface' => true,
                'remove_logo' => false,
            ])
            ->assertForbidden();
    }

    public function test_shared_neighborhood_can_be_imported_by_two_units_without_sharing_private_records(): void
    {
        $firstUnit = Gabinete::factory()->create([
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
        ]);
        $entidade = $firstUnit->entidade;
        $entidade->forceFill(['municipio' => 'Fortaleza', 'estado' => 'CE'])->save();
        $secondUnit = Gabinete::factory()->for($entidade, 'entidade')->create([
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
        ]);
        $manager = User::factory()->councilor()->forGabinete($firstUnit)->create();
        GabineteMembro::query()->create([
            'gabinete_id' => $secondUnit->id,
            'usuario_id' => $manager->id,
            'papel' => GabineteRole::Manager,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $this->actingAs($manager)
            ->post(route('entidades.neighborhoods.store', $entidade), [
                'name' => 'Centro',
                'active' => true,
            ])
            ->assertRedirect();

        $reference = EntidadeBairro::query()->where('nome', 'Centro')->firstOrFail();

        foreach ([$firstUnit, $secondUnit] as $gabinete) {
            $this->actingAs($manager)
                ->post(route('context.neighborhoods.references.import', [
                    'entidade' => $entidade,
                    'gabinete' => $gabinete,
                    'reference' => $reference,
                ]))
                ->assertRedirect();
        }

        $this->assertSame(2, Bairro::withoutGlobalScopes()
            ->where('entidade_bairro_id', $reference->id)
            ->distinct()
            ->count('gabinete_id'));
        $this->assertDatabaseCount('cidadaos', 0);
    }

    public function test_reference_from_another_entidade_cannot_be_imported(): void
    {
        $allowedUnit = Gabinete::factory()->create();
        $blockedUnit = Gabinete::factory()->create();
        $user = User::factory()->chiefOfStaff()->forGabinete($allowedUnit)->create();
        $reference = $blockedUnit->entidade->bairros()->create([
            'nome' => 'Outro bairro',
            'municipio' => $blockedUnit->municipio,
            'estado' => $blockedUnit->estado,
            'ativo' => true,
        ]);

        $this->actingAs($user)
            ->post(route('context.neighborhoods.references.import', [
                'entidade' => $allowedUnit->entidade,
                'gabinete' => $allowedUnit,
                'reference' => $reference,
            ]))
            ->assertNotFound();
    }
}
