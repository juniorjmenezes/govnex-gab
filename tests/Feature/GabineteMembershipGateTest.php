<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `OfficeMember::constrainActiveUsers()`/`isActiveMember()`/
 * `inactiveOverrides()` são o ponto único que decide "é membro ativo deste
 * gabinete" em todo o sistema (demandas, compromissos, eventos, digests e
 * convocações de WhatsApp). O caso central que eles existem para resolver:
 * o toggle "pausar" do Time grava só `gabinete_membros.ativo = false`,
 * nunca `users.gabinete_id`/`is_active` — então esses dois campos, usados
 * isoladamente, continuariam tratando a pessoa pausada como ativa.
 */
class GabineteMembershipGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_any_membership_row_is_still_treated_as_active(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->forGabinete($office)->create();

        $this->assertTrue(OfficeMember::isActiveMember($user, $office->id));
    }

    public function test_a_user_with_an_active_membership_row_is_active(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->forGabinete($office)->create();
        $this->membership($office, $user, ativo: true);

        $this->assertTrue(OfficeMember::isActiveMember($user, $office->id));
    }

    public function test_a_user_paused_via_the_team_screen_stops_being_an_active_member(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->forGabinete($office)->create();
        $this->membership($office, $user, ativo: false);

        $this->assertFalse(OfficeMember::isActiveMember($user, $office->id));
    }

    public function test_an_inactive_user_is_never_an_active_member_even_without_a_membership_row(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->forGabinete($office)->create(['is_active' => false]);

        $this->assertFalse(OfficeMember::isActiveMember($user, $office->id));
    }

    public function test_constrain_active_users_excludes_only_the_explicitly_paused_member(): void
    {
        $office = Office::factory()->create();
        $untouched = User::factory()->forGabinete($office)->create();
        $paused = User::factory()->forGabinete($office)->create();
        $this->membership($office, $paused, ativo: false);

        $query = User::query();
        OfficeMember::constrainActiveUsers($query, $office->id);
        $ids = $query->pluck('id')->all();

        $this->assertContains($untouched->id, $ids);
        $this->assertNotContains($paused->id, $ids);
    }

    public function test_inactive_overrides_only_lists_explicitly_paused_members(): void
    {
        $office = Office::factory()->create();
        $active = User::factory()->forGabinete($office)->create();
        $this->membership($office, $active, ativo: true);
        $paused = User::factory()->forGabinete($office)->create();
        $this->membership($office, $paused, ativo: false);

        $overrides = OfficeMember::inactiveOverrides([$office->id]);

        $this->assertArrayNotHasKey($active->id, $overrides[$office->id] ?? []);
        $this->assertTrue($overrides[$office->id][$paused->id] ?? false);
    }

    /**
     * `UserEntityMembershipObserver` já cria o vínculo ao salvar o usuário
     * com `gabinete_id` — por isso `updateOrCreate`, não `create`.
     */
    private function membership(Office $office, User $user, bool $ativo): OfficeMember
    {
        return OfficeMember::query()->updateOrCreate(
            ['gabinete_id' => $office->id, 'usuario_id' => $user->id],
            [
                'papel' => AccessRole::Operator,
                'ativo' => $ativo,
                'ingressou_em' => now(),
                'desativado_em' => $ativo ? null : now(),
            ],
        );
    }
}
