<?php

namespace App\Http\Controllers;

use App\Models\Atendimento;
use App\Models\Cidadao;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoterProspectingMapController extends Controller
{
    private const MARKER_LIMIT = 5000;

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Cidadao::class);
        $office = $request->user()?->gabinete;

        $voters = Cidadao::query()->where('eleitor', true);
        $locatedVoters = (clone $voters)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
        $locatedCount = (clone $locatedVoters)->count();

        $lastVisits = Atendimento::query()
            ->where('visita_domiciliar', true)
            ->selectRaw('cidadao_id, MAX(atendido_em) as last_visited_at')
            ->groupBy('cidadao_id');

        $markers = $locatedVoters
            ->leftJoinSub($lastVisits, 'visits', fn ($join) => $join
                ->on('visits.cidadao_id', '=', 'cidadaos.id'))
            ->select([
                'cidadaos.id',
                'cidadaos.nome',
                'cidadaos.bairro_id',
                'cidadaos.endereco',
                'cidadaos.numero',
                'cidadaos.latitude',
                'cidadaos.longitude',
                'visits.last_visited_at',
            ])
            ->with('bairro:id,nome')
            ->orderBy('cidadaos.nome')
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
                'lastVisitedAt' => $citizen->getAttribute('last_visited_at')
                    ? CarbonImmutable::parse($citizen->getAttribute('last_visited_at'))->toIso8601String()
                    : null,
            ])
            ->values();

        return Inertia::render('voters/prospecting-map', [
            'markers' => $markers,
            'officeState' => $office?->estado,
            'summary' => [
                'totalVoters' => (clone $voters)->count(),
                'locatedVoters' => $locatedCount,
                'withoutLocation' => (clone $voters)
                    ->where(function ($query): void {
                        $query->whereNull('latitude')
                            ->orWhereNull('longitude');
                    })
                    ->count(),
                'visitedCount' => (clone $voters)
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereExists(fn ($query) => $query
                        ->selectRaw(1)
                        ->from('atendimentos')
                        ->whereColumn('atendimentos.cidadao_id', 'cidadaos.id')
                        ->whereColumn('atendimentos.gabinete_id', 'cidadaos.gabinete_id')
                        ->where('atendimentos.visita_domiciliar', true))
                    ->count(),
                'truncated' => $locatedCount > self::MARKER_LIMIT,
            ],
        ]);
    }
}
