<?php

namespace Tests\Feature;

use App\Models\Bairro;
use App\Models\Cidadao;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VoterProspectingMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_contains_only_located_voters_from_current_office(): void
    {
        $office = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->create();
        $user = User::factory()->operator()->forGabinete($office)->create();
        $neighborhood = Bairro::factory()->forGabinete($office)->create();
        $locatedVoter = Cidadao::factory()
            ->forGabinete($office, $neighborhood)
            ->create([
                'nome' => 'Eleitor Localizado',
                'eleitor' => true,
                'endereco' => 'Rua Principal',
                'numero' => '100',
                'latitude' => -3.731862,
                'longitude' => -38.526669,
            ]);
        Cidadao::factory()->forGabinete($office)->create([
            'eleitor' => true,
            'latitude' => null,
            'longitude' => null,
        ]);
        Cidadao::factory()->forGabinete($office)->create([
            'eleitor' => false,
            'latitude' => -3.72,
            'longitude' => -38.52,
        ]);
        Cidadao::factory()->forGabinete($otherOffice)->create([
            'eleitor' => true,
            'latitude' => -3.70,
            'longitude' => -38.50,
        ]);

        $this->actingAs($user)
            ->get(route('voters.prospecting-map'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('voters/prospecting-map')
                ->has('markers', 1)
                ->where('markers.0.id', $locatedVoter->id)
                ->where('markers.0.name', 'Eleitor Localizado')
                ->where('markers.0.address', 'Rua Principal, 100')
                ->where('markers.0.neighborhood', $neighborhood->nome)
                ->where('summary.totalVoters', 2)
                ->where('summary.locatedVoters', 1)
                ->where('summary.withoutLocation', 1)
                ->where('summary.truncated', false));
    }

    public function test_platform_administrator_cannot_access_voter_prospecting_map(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get(route('voters.prospecting-map'))
            ->assertForbidden();
    }
}
