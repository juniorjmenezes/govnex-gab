<?php

namespace App\Http\Controllers;

use App\Models\Cidadao;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitizenLocationController extends Controller
{
    public function __invoke(Request $request, GeocodingService $geocoding): JsonResponse
    {
        $this->authorize('create', Cidadao::class);

        $validated = $request->validate([
            'logradouro' => ['required', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'municipio' => ['required', 'string', 'max:120'],
            'estado' => ['required', 'string', 'size:2'],
        ]);

        try {
            return response()->json([
                'results' => $geocoding->search(
                    street: $validated['logradouro'],
                    number: $validated['numero'] ?? null,
                    neighborhood: $validated['bairro'] ?? null,
                    city: $validated['municipio'],
                    state: $validated['estado'],
                ),
            ]);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Não foi possível consultar o endereço agora. Tente novamente.',
            ], 502);
        }
    }
}
