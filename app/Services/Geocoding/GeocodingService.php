<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeocodingService
{
    /**
     * @return list<array{label: string, latitude: float, longitude: float, precision: 'address'|'street'|'municipality'}>
     */
    public function search(
        string $street,
        ?string $number,
        ?string $neighborhood,
        string $city,
        string $state,
        ?string $postalCode = null,
    ): array {
        $street = $this->normalize($street);
        $number = $number ? $this->normalize($number) : null;
        $neighborhood = $neighborhood ? $this->normalize($neighborhood) : null;
        $city = $this->normalize($city);
        $state = mb_strtoupper($this->normalize($state));
        $postalCode = $postalCode ? preg_replace('/\D+/', '', $postalCode) : null;
        $postalCode = $postalCode !== null && strlen($postalCode) === 8 ? $postalCode : null;

        $numberedStreet = trim(implode(' ', array_filter([$number, $street])));
        $attempts = [
            // O CEP é o que mais aumenta a chance de o Nominatim cravar o
            // número no Brasil — sem ele a consulta cai no logradouro inteiro.
            ...($postalCode !== null && $number !== null ? [[
                'precision' => 'address',
                'query' => [
                    'street' => $numberedStreet,
                    'postalcode' => $postalCode,
                    'country' => 'Brasil',
                ],
            ]] : []),
            [
                'precision' => 'address',
                'query' => [
                    'street' => $numberedStreet,
                    'city' => $city,
                    'state' => $state,
                ],
            ],
            ...($number !== null ? [[
                'precision' => 'address',
                'query' => [
                    'q' => implode(', ', array_filter([
                        $numberedStreet,
                        $neighborhood,
                        $city,
                        $state,
                        'Brasil',
                    ])),
                ],
            ]] : []),
            [
                'precision' => 'street',
                'query' => [
                    'street' => $street,
                    'city' => $city,
                    'state' => $state,
                ],
            ],
            [
                'precision' => 'street',
                'query' => [
                    'q' => implode(', ', array_filter([
                        $street,
                        $neighborhood,
                        $city,
                        $state,
                        'Brasil',
                    ])),
                ],
            ],
            [
                'precision' => 'municipality',
                'query' => [
                    'city' => $city,
                    'state' => $state,
                ],
            ],
        ];

        // Guarda o melhor resultado impreciso enquanto ainda houver tentativa
        // capaz de cravar o número: o Nominatim responde com o logradouro
        // inteiro quando não conhece a numeração, e antes disso o serviço
        // devolvia esse ponto rotulado como "endereço exato".
        $fallback = [];

        foreach ($attempts as $index => $attempt) {
            if ($index > 0) {
                usleep(((int) config('services.geocoding.minimum_interval_ms', 1100)) * 1000);
            }

            $results = $this->searchAttempt($attempt['query'], $attempt['precision']);

            if ($results === []) {
                continue;
            }

            if ($results[0]['precision'] === $attempt['precision']) {
                return $results;
            }

            if ($fallback === []) {
                $fallback = $results;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, string>  $query
     * @param  'address'|'street'|'municipality'  $precision
     * @return list<array{label: string, latitude: float, longitude: float, precision: 'address'|'street'|'municipality'}>
     */
    private function searchAttempt(array $query, string $precision): array
    {
        $parameters = [
            ...$query,
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'countrycodes' => 'br',
            'limit' => '5',
            'email' => (string) config('services.geocoding.contact_email'),
        ];
        $parameters = array_filter($parameters);
        ksort($parameters);

        $cacheKey = 'geocoding:v2:'.hash('sha256', implode('|', [
            (string) config('services.geocoding.url'),
            http_build_query($parameters),
        ]));

        $cached = Cache::get($cacheKey);

        $cachedResults = $this->validatedCachedResults($cached);

        if ($cachedResults !== []) {
            return $cachedResults;
        }

        $response = Http::acceptJson()
            ->withUserAgent((string) config('services.geocoding.user_agent'))
            ->timeout(10)
            ->get((string) config('services.geocoding.url'), $parameters)
            ->throw();

        $payload = $response->json();
        $results = [];

        if (! is_array($payload)) {
            return $results;
        }

        foreach ($payload as $item) {
            if (! is_array($item)
                || ! is_numeric($item['lat'] ?? null)
                || ! is_numeric($item['lon'] ?? null)) {
                continue;
            }

            $results[] = [
                'label' => (string) ($item['display_name'] ?? implode(', ', $query)),
                'latitude' => (float) $item['lat'],
                'longitude' => (float) $item['lon'],
                'precision' => $this->resolvePrecision($precision, $item),
            ];
        }

        if ($results !== []) {
            Cache::put($cacheKey, $results, now()->addDays(30));
        }

        return $results;
    }

    /**
     * A precisão pedida é só a intenção da tentativa. Sem `house_number` na
     * resposta, o ponto é o logradouro — dizer "endereço" ali faria o mapa
     * afirmar uma exatidão que o dado não tem.
     *
     * @param  'address'|'street'|'municipality'  $intended
     * @param  array<string, mixed>  $item
     * @return 'address'|'street'|'municipality'
     */
    private function resolvePrecision(string $intended, array $item): string
    {
        if ($intended !== 'address') {
            return $intended;
        }

        $address = $item['address'] ?? null;
        $houseNumber = is_array($address) ? ($address['house_number'] ?? null) : null;

        return $houseNumber === null || $houseNumber === '' ? 'street' : 'address';
    }

    /**
     * @return list<array{label: string, latitude: float, longitude: float, precision: 'address'|'street'|'municipality'}>
     */
    private function validatedCachedResults(mixed $cached): array
    {
        if (! is_array($cached)) {
            return [];
        }

        $results = [];

        foreach ($cached as $item) {
            if (! is_array($item)
                || ! is_string($item['label'] ?? null)
                || ! is_numeric($item['latitude'] ?? null)
                || ! is_numeric($item['longitude'] ?? null)) {
                continue;
            }

            $precision = match ($item['precision'] ?? null) {
                'address' => 'address',
                'street' => 'street',
                'municipality' => 'municipality',
                default => null,
            };

            if ($precision === null) {
                continue;
            }

            $results[] = [
                'label' => $item['label'],
                'latitude' => (float) $item['latitude'],
                'longitude' => (float) $item['longitude'],
                'precision' => $precision,
            ];
        }

        return $results;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
