<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Enums\OfficeType;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A entrada após o login (/dashboard) não pode depender só de
 * users.gabinete_id, gravado na criação da conta: a pessoa pode ter sido
 * removida do gabinete de origem e seguir ativa em outro.
 */
class LegacyEntryAfterMembershipChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutFollowingLegacyTenantRedirects();
    }

    public function test_user_removed_from_origin_office_enters_another_active_office(): void
    {
        $origin = Office::factory()->create();
        $other = Office::factory()->for($origin->entidade, 'entidade')->create([
            'tipo_gabinete' => OfficeType::AdministrativeDepartment,
        ]);
        $user = User::factory()->operator()->forGabinete($origin)->create();

        OfficeMember::query()->create([
            'gabinete_id' => $other->id,
            'usuario_id' => $user->id,
            'papel' => AccessRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);
        $this->removeFrom($origin, $user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(sprintf(
                '/entidades/%s/gabinetes/%s/dashboard',
                $origin->entidade->slug,
                $other->slug,
            ));
    }

    public function test_user_without_any_active_office_goes_to_the_directory(): void
    {
        $origin = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($origin)->create();
        $this->removeFrom($origin, $user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('entidades.index'));
    }

    public function test_active_origin_office_keeps_being_the_entry_point(): void
    {
        $origin = Office::factory()->create();
        $user = User::factory()->operator()->forGabinete($origin)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(sprintf(
                '/entidades/%s/gabinetes/%s/dashboard',
                $origin->entidade->slug,
                $origin->slug,
            ));
    }

    private function removeFrom(Office $gabinete, User $user): void
    {
        OfficeMember::query()
            ->where('gabinete_id', $gabinete->id)
            ->where('usuario_id', $user->id)
            ->update(['ativo' => false, 'desativado_em' => now()]);
    }
}
