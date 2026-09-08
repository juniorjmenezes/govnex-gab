<?php

namespace App\Http\Controllers;

use App\Models\Cidadao;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoterProspectingMapController extends Controller
{
    private const MARKER_LIMIT = 5000;

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Cidadao::class);

        $voters = Cidadao::query()->where('eleitor', true);
        $locatedVoters = (clone $voters)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
        $locatedCount = (clone $locatedVoters)->count();

        $markers = $locatedVoters
            ->select([
                'id',
                'nome',
                'bairro_id',
                'endereco',
                'numero',
                'latitude',
                'longitude',
            ])
            ->with('bairro:id,nome')
            ->orderBy('nome')
            ->limit(self::MARKER_LIMIT)
            ->get()
            ->map(fn (Cidadao $citizen): array => [
                'id' => $citizen->id,
                'name' => $citizen->nome,
                'address' => implode(', ', array_filter([
                    $citizen->endereco,
                    $citizen->numero,
                ])),
                'neighborhoodId' => $citizen->bairro_id,
                'neighborhood' => $citizen->bairro?->nome,
                'latitude' => (float) $citizen->latitude,
                'longitude' => (float) $citizen->longitude,
            ])
            ->values();

        return Inertia::render('voters/prospecting-map', [
            'markers' => $markers,
            'summary' => [
                'totalVoters' => (clone $voters)->count(),
                'locatedVoters' => $locatedCount,
                'withoutLocation' => (clone $voters)
                    ->where(function ($query): void {
                        $query->whereNull('latitude')
                            ->orWhereNull('longitude');
                    })
                    ->count(),
                'truncated' => $locatedCount > self::MARKER_LIMIT,
            ],
        ]);
    }
}
