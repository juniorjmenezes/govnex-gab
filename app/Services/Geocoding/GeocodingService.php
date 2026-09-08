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
    ): array {
        $street = $this->normalize($street);
        $number = $number ? $this->normalize($number) : null;
        $neighborhood = $neighborhood ? $this->normalize($neighborhood) : null;
        $city = $this->normalize($city);
        $state = mb_strtoupper($this->normalize($state));

        $attempts = [
            [
                'precision' => 'address',
                'query' => [
                    'street' => trim(implode(' ', array_filter([$number, $street]))),
                    'city' => $city,
                    'state' => $state,
                ],
            ],
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

        foreach ($attempts as $index => $attempt) {
            if ($index > 0) {
                usleep(((int) config('services.geocoding.minimum_interval_ms', 1100)) * 1000);
            }

            $results = $this->searchAttempt($attempt['query'], $attempt['precision']);

            if ($results !== []) {
                return $results;
            }
        }

        return [];
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
                'precision' => $precision,
            ];
        }

        if ($results !== []) {
            Cache::put($cacheKey, $results, now()->addDays(30));
        }

        return $results;
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
