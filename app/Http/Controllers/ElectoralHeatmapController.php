<?php

namespace App\Http\Controllers;

use App\Models\Gabinete;
use App\Models\LocalVotacaoEleitoral;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ElectoralHeatmapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);

        $office = Gabinete::query()
            ->with('candidatoTitular.eleicao')
            ->findOrFail($user->gabinete_id);
        $candidate = $office->candidatoTitular;

        if ($candidate === null) {
            return Inertia::render('voters/electoral-map', [
                'points' => [],
                'summary' => [
                    'configured' => false,
                    // Diferencia "admin nunca cadastrou o número" de "número
                    // cadastrado, mas nenhuma candidatura do TSE bateu com
                    // ele" — só a administração da plataforma resolve os dois
                    // casos, mas a mensagem certa evita o vereador achar que
                    // precisa digitar algo aqui.
                    'reason' => $office->numero_eleitoral === null
                        ? 'number_missing'
                        : 'candidate_unmatched',
                    'candidate' => null,
                    'election' => null,
                    'totalVotes' => 0,
                    'totalLocations' => 0,
                    'locatedLocations' => 0,
                    'pendingGeocoding' => 0,
                ],
            ]);
        }

        /** @var Collection<int, int> $votesByLocation */
        $votesByLocation = DB::table('votos_secao_candidato')
            ->join('secoes_eleitorais', 'secoes_eleitorais.id', '=', 'votos_secao_candidato.secao_eleitoral_id')
            ->where('votos_secao_candidato.candidato_politico_id', $candidate->id)
            ->selectRaw('secoes_eleitorais.local_votacao_eleitoral_id as local_id, SUM(votos_secao_candidato.votos) as votes')
            ->groupBy('secoes_eleitorais.local_votacao_eleitoral_id')
            ->pluck('votes', 'local_id')
            ->map(fn (mixed $votes): int => (int) $votes);

        $locations = LocalVotacaoEleitoral::query()
            ->whereIn('id', $votesByLocation->keys())
            ->get(['id', 'nome', 'endereco', 'bairro', 'latitude', 'longitude']);

        // Contagem de seções por local: informação secundária no popup (não
        // essencial para nenhum cálculo, só contexto de quantas urnas/seções
        // compõem aquele total de votos).
        /** @var Collection<int, int> $sectionsByLocation */
        $sectionsByLocation = DB::table('secoes_eleitorais')
            ->whereIn('local_votacao_eleitoral_id', $locations->pluck('id'))
            ->selectRaw('local_votacao_eleitoral_id as local_id, COUNT(*) as sections')
            ->groupBy('local_votacao_eleitoral_id')
            ->pluck('sections', 'local_id')
            ->map(fn (mixed $sections): int => (int) $sections);

        $points = $locations
            ->filter(fn (LocalVotacaoEleitoral $location): bool => $location->latitude !== null
                && $location->longitude !== null)
            ->map(fn (LocalVotacaoEleitoral $location): array => [
                'id' => $location->id,
                'name' => $location->nome,
                'address' => $location->endereco,
                'neighborhood' => $location->bairro,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'votes' => $votesByLocation->get($location->id, 0),
                'sections' => $sectionsByLocation->get($location->id, 0),
            ])
            ->values();

        return Inertia::render('voters/electoral-map', [
            'points' => $points,
            'summary' => [
                'configured' => true,
                'reason' => null,
                'candidate' => [
                    'name' => $candidate->nome_urna,
                    'party' => $candidate->partido_sigla,
                    'number' => $candidate->numero,
                ],
                'election' => [
                    'name' => $candidate->eleicao->nome,
                    'year' => $candidate->eleicao->ano,
                ],
                'totalVotes' => $votesByLocation->sum(),
                'totalLocations' => $locations->count(),
                'locatedLocations' => $points->count(),
                'pendingGeocoding' => $locations->count() - $points->count(),
            ],
        ]);
    }
}
