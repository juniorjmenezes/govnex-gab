<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Enums\UserRole;
use App\Models\Citizen;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Autorização e isolamento decididos pelo contexto da URL (`OfficeContext`),
 * sem projetar o gabinete/papel nos campos `users.gabinete_id`/`users.role`.
 */
class RequestContextAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_role_and_office_come_from_the_url_context_not_from_the_account(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->for($home->entidade, 'entidade')->create();
        $user = User::factory()->administrator()->forGabinete($home)->create();
        $this->joinOffice($user, $other, AccessRole::Auditor);

        $homeCitizen = Citizen::factory()->forGabinete($home)->create();
        $otherCitizen = Citizen::factory()->forGabinete($other)->create();
        $context = ['entidade' => $other->entidade, 'gabinete' => $other];

        $this->actingAs($user)
            ->get(route('context.citizens.index', $context))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('citizens.data', 1)
                ->where('citizens.data.0.id', $otherCitizen->id)
                ->where('auth.user.role', UserRole::Auditor->value)
                ->where('auth.user.gabinete_id', $other->id));

        $this->assertNotContains(
            $this->actingAs($user)->get(route('context.citizens.show', [...$context, 'cidadao' => $homeCitizen]))->status(),
            [200],
        );

        // Administrador na conta, auditor no gabinete da URL: não escreve aqui.
        $this->actingAs($user)
            ->post(route('context.citizens.store', $context), ['nome' => 'Tentativa'])
            ->assertForbidden();

        // O cadastro da conta não é alterado, nem em memória.
        $this->assertSame($home->id, $user->gabinete_id);
        $this->assertSame(UserRole::Administrator, $user->role);
        $this->assertSame($home->id, $user->fresh()->gabinete_id);
        $this->assertSame(UserRole::Administrator, $user->fresh()->role);
    }

    public function test_operator_in_the_url_office_cannot_delete_even_if_the_account_is_administrator(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->for($home->entidade, 'entidade')->create();
        $user = User::factory()->administrator()->forGabinete($home)->create();
        $this->joinOffice($user, $other, AccessRole::Operator);
        $citizen = Citizen::factory()->forGabinete($other)->create();

        $this->actingAs($user)
            ->delete(route('context.citizens.destroy', [
                'entidade' => $other->entidade,
                'gabinete' => $other,
                'cidadao' => $citizen,
            ]))
            ->assertForbidden();

        $this->assertNotNull($citizen->fresh());
    }

    public function test_root_acts_in_the_url_office_and_only_sees_its_records(): void
    {
        $first = Office::factory()->create();
        $second = Office::factory()->create();
        $root = User::factory()->root()->create();
        $foreign = Citizen::factory()->forGabinete($first)->create();
        $own = Citizen::factory()->forGabinete($second)->create();
        $context = ['entidade' => $second->entidade, 'gabinete' => $second];

        $this->actingAs($root)
            ->get(route('context.citizens.index', $context))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('citizens.data', 1)
                ->where('citizens.data.0.id', $own->id)
                ->where('auth.user.role', UserRole::Root->value)
                ->where('auth.user.gabinete_id', $second->id));

        $this->actingAs($root)
            ->get(route('context.citizens.show', [...$context, 'cidadao' => $own]))
            ->assertOk();

        $this->assertNotContains(
            $this->actingAs($root)->get(route('context.citizens.show', [...$context, 'cidadao' => $foreign]))->status(),
            [200],
        );

        $this->assertNull($root->fresh()->gabinete_id);
    }

    public function test_member_of_another_entidade_never_reaches_its_records(): void
    {
        $mine = Office::factory()->create();
        $theirs = Office::factory()->create();
        $user = User::factory()->administrator()->forGabinete($mine)->create();
        $foreign = Citizen::factory()->forGabinete($theirs)->create();

        $this->actingAs($user)
            ->get(route('context.citizens.show', [
                'entidade' => $mine->entidade,
                'gabinete' => $mine,
                'cidadao' => $foreign,
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('context.citizens.index', ['entidade' => $theirs->entidade, 'gabinete' => $theirs]))
            ->assertForbidden();
    }

    private function joinOffice(User $user, Office $office, AccessRole $role): void
    {
        OfficeMember::query()->create([
            'gabinete_id' => $office->id,
            'usuario_id' => $user->id,
            'papel' => $role,
            'ativo' => true,
            'ingressou_em' => now(),
        ]);
    }
}
