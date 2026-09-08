<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IbgeLocationService
{
    /** @return array<int, array{id: int, nome: string}> */
    public function municipalities(string $state): array
    {
        $state = strtoupper(trim($state));

        return Cache::remember("ibge.municipalities.{$state}", now()->addDay(), function () use ($state): array {
            $response = Http::acceptJson()->timeout(5)->get(
                "https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$state}/municipios",
            );

            if ($response->failed()) {
                return [];
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return [];
            }

            return collect($payload)
                ->filter(fn (mixed $item): bool => is_array($item) && isset($item['id'], $item['nome']))
                ->map(fn (array $item): array => ['id' => (int) $item['id'], 'nome' => (string) $item['nome']])
                ->values()
                ->all();
        });
    }

    public function municipalityBelongsToState(string $state, string $municipality): bool
    {
        $municipality = mb_strtolower(trim($municipality));
        $municipalities = $this->municipalities($state);

        // Do not block writes when IBGE is temporarily unavailable.
        return $municipalities === [] || collect($municipalities)->contains(
            fn (array $item): bool => mb_strtolower($item['nome']) === $municipality,
        );
    }
}
