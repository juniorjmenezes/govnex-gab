<?php

namespace Tests\Feature;

use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_chief_can_manage_advisor_from_same_gabinete_only(): void
    {
        $gabinete = Gabinete::factory()->create();
        $otherGabinete = Gabinete::factory()->create();
        $chief = User::factory()->chiefOfStaff()->forGabinete($gabinete)->create();
        $ownAdvisor = User::factory()->advisor()->forGabinete($gabinete)->create();
        $otherAdvisor = User::factory()->advisor()->forGabinete($otherGabinete)->create();

        $this->assertTrue(Gate::forUser($chief)->allows('update', $ownAdvisor));
        $this->assertFalse(Gate::forUser($chief)->allows('update', $otherAdvisor));
    }

    public function test_advisor_cannot_manage_team(): void
    {
        $gabinete = Gabinete::factory()->create();
        $advisor = User::factory()->advisor()->forGabinete($gabinete)->create();
        $teammate = User::factory()->advisor()->forGabinete($gabinete)->create();

        $this->assertFalse(Gate::forUser($advisor)->allows('update', $teammate));
        $this->assertFalse(Gate::forUser($advisor)->allows('create', User::class));
    }

    public function test_platform_admin_can_manage_users_from_any_gabinete(): void
    {
        $admin = User::factory()->root()->create();
        $target = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('update', $target));
    }
}
