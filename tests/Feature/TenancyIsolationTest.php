<?php

namespace Tests\Feature;

use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/gabinetes/{gabinete}', fn (Gabinete $gabinete) => response()->json([
            'id' => $gabinete->id,
        ]));
    }

    public function test_user_only_queries_own_gabinete(): void
    {
        $ownGabinete = Gabinete::factory()->create();
        $otherGabinete = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($ownGabinete)->create();

        $this->actingAs($user);

        $visibleIds = Gabinete::query()->pluck('id')->all();

        $this->assertSame([$ownGabinete->id], $visibleIds);
        $this->assertNotContains($otherGabinete->id, $visibleIds);
    }

    public function test_route_model_binding_returns_not_found_for_another_gabinete(): void
    {
        $ownGabinete = Gabinete::factory()->create();
        $otherGabinete = Gabinete::factory()->create();
        $user = User::factory()->forGabinete($ownGabinete)->create();

        $this->actingAs($user)
            ->get("/_test/gabinetes/{$otherGabinete->id}")
            ->assertNotFound();

        $this->get("/_test/gabinetes/{$ownGabinete->id}")
            ->assertOk()
            ->assertJson(['id' => $ownGabinete->id]);
    }

    public function test_platform_admin_can_query_all_gabinetes(): void
    {
        $gabinetes = Gabinete::factory()->count(2)->create();
        $admin = User::factory()->root()->create();

        $this->actingAs($admin);

        $this->assertEqualsCanonicalizing(
            $gabinetes->modelKeys(),
            Gabinete::query()->pluck('id')->all(),
        );
    }
}
