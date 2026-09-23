<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_office_member_appears_in_canonical_team_list(): void
    {
        $office = Gabinete::factory()->create();
        $leader = User::factory()->administrator()->forGabinete($office)->create();
        $member = User::factory()->operator()->create();
        GabineteMembro::query()->create([
            'gabinete_id' => $office->id,
            'usuario_id' => $member->id,
            'papel' => AccessRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $this->actingAs($leader)
            ->get(route('context.team.index', [
                'entidade' => $office->entidade,
                'gabinete' => $office,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('team/index')
                ->where('members', fn ($members) => collect($members)
                    ->contains(fn (array $item): bool => $item['id'] === $member->id
                        && $item['role'] === AccessRole::Operator->value)));
    }

    public function test_removing_office_access_preserves_global_user_and_other_memberships(): void
    {
        $office = Gabinete::factory()->create();
        $leader = User::factory()->administrator()->forGabinete($office)->create();
        $otherOffice = Gabinete::factory()->create();
        $member = User::factory()->operator()->forGabinete($otherOffice)->create();
        GabineteMembro::query()->create([
            'gabinete_id' => $office->id,
            'usuario_id' => $member->id,
            'papel' => AccessRole::Operator,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);

        $this->actingAs($leader)
            ->delete(route('context.team.destroy', [
                'entidade' => $office->entidade,
                'gabinete' => $office,
                'usuario' => $member,
            ]))
            ->assertRedirect(route('team.index'));

        $this->assertNotSoftDeleted($member);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $office->id,
            'usuario_id' => $member->id,
            'ativo' => false,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $otherOffice->id,
            'usuario_id' => $member->id,
            'ativo' => true,
        ]);
    }

    public function test_existing_account_can_be_linked_without_replacing_its_password(): void
    {
        $office = Gabinete::factory()->create();
        $leader = User::factory()->administrator()->forGabinete($office)->create();
        $existing = User::factory()->operator()->create();
        $passwordHash = $existing->password;

        $this->actingAs($leader)
            ->post(route('context.team.store', [
                'entidade' => $office->entidade,
                'gabinete' => $office,
            ]), [
                'name' => $existing->name,
                'email' => $existing->email,
                'role' => AccessRole::Operator->value,
            ])
            ->assertRedirect(route('team.index'));

        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $office->id,
            'usuario_id' => $existing->id,
            'papel' => AccessRole::Operator->value,
            'ativo' => true,
        ]);
        $this->assertSame($passwordHash, $existing->fresh()->password);
    }
}
