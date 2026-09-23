<?php

namespace Tests\Feature\Admin;

use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_office_listing_query_count_does_not_grow_per_row(): void
    {
        $admin = User::factory()->root()->create();

        Gabinete::factory()->count(15)->create()->each(function (Gabinete $office): void {
            User::factory()->forGabinete($office)->administrator()->create();
        });

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)
            ->get(route('admin.offices.index'))
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            15,
            $queryCount,
            "A listagem executou {$queryCount} consultas; verifique regressões N+1.",
        );
    }
}
