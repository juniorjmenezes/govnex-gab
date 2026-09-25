<?php

namespace Tests\Feature;

use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Pessoas e vínculos migraram para o Govnex Hub: `create`/`update`/`delete`
 * da UserPolicy ficaram desligados para todo mundo, inclusive root (que de
 * todo modo não passa por esta Policy — root é gerido localmente por
 * Admin\RootUserController, com sua própria autorização). Ver
 * docs/INTEGRACAO_GOVNEX_HUB.md.
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_chief_cannot_manage_advisor_locally_anymore(): void
    {
        $gabinete = Gabinete::factory()->create();
        $otherGabinete = Gabinete::factory()->create();
        $chief = User::factory()->administrator()->forGabinete($gabinete)->create();
        $ownAdvisor = User::factory()->operator()->forGabinete($gabinete)->create();
        $otherAdvisor = User::factory()->operator()->forGabinete($otherGabinete)->create();

        $this->assertFalse(Gate::forUser($chief)->allows('update', $ownAdvisor));
        $this->assertFalse(Gate::forUser($chief)->allows('update', $otherAdvisor));
    }

    public function test_advisor_cannot_manage_team(): void
    {
        $gabinete = Gabinete::factory()->create();
        $advisor = User::factory()->operator()->forGabinete($gabinete)->create();
        $teammate = User::factory()->operator()->forGabinete($gabinete)->create();

        $this->assertFalse(Gate::forUser($advisor)->allows('update', $teammate));
        $this->assertFalse(Gate::forUser($advisor)->allows('create', User::class));
    }

    public function test_policy_no_longer_grants_write_access_to_anyone(): void
    {
        $admin = User::factory()->root()->create();
        $target = User::factory()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('update', $target));
        $this->assertFalse(Gate::forUser($admin)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $target));
    }
}
