<?php

namespace Tests\Feature;

use App\Models\PartidoCor;
use Database\Seeders\PartidoCorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartidoCorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_official_party_colors(): void
    {
        (new PartidoCorSeeder)->run();

        $this->assertSame(30, PartidoCor::query()->count());
        $this->assertSame('#E30613', PartidoCor::query()->where('sigla', 'PT')->value('cor'));
        $this->assertSame('#00A4E8', PartidoCor::query()->where('sigla', 'UNIÃO')->value('cor'));
        $this->assertSame('#111111', PartidoCor::query()->where('sigla', 'UP')->value('cor'));
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_rows(): void
    {
        (new PartidoCorSeeder)->run();
        (new PartidoCorSeeder)->run();

        $this->assertSame(30, PartidoCor::query()->count());
    }
}
