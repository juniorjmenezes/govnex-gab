<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

            $results = $this->searchAttempt($attempt['query'], $attempt['precision'], $city);

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
    private function searchAttempt(array $query, string $precision, string $expectedCity): array
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

        $cacheKey = 'geocoding:v3:'.hash('sha256', implode('|', [
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

            // O `city=`/`q=` do Nominatim é só uma dica de busca, não um
            // filtro — ele pode cravar o logradouro num município vizinho de
            // nome parecido (ex.: "Cruz" e "Bela Cruz", ambos no CE, com
            // ruas homônimas). Sem essa checagem, o mapa aponta a casa
            // errada em silêncio.
            if (! $this->matchesExpectedCity($item, $expectedCity)) {
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
     * Confere se o resultado realmente caiu no município pedido. O Nominatim
     * devolve o nível administrativo em chaves diferentes conforme o tipo de
     * assentamento (cidade, vila, distrito...); sem `address` na resposta,
     * deixa passar — a maioria das consultas por `q=` livre não devolve esse
     * bloco, e barrar aí descartaria resultado bom demais.
     *
     * @param  array<string, mixed>  $item
     */
    private function matchesExpectedCity(array $item, string $expectedCity): bool
    {
        $address = $item['address'] ?? null;

        if (! is_array($address)) {
            return true;
        }

        $candidates = array_filter([
            $address['city'] ?? null,
            $address['town'] ?? null,
            $address['village'] ?? null,
            $address['municipality'] ?? null,
            $address['county'] ?? null,
        ], 'is_string');

        if ($candidates === []) {
            return true;
        }

        $expected = $this->canonical($expectedCity);

        foreach ($candidates as $candidate) {
            if ($this->canonical($candidate) === $expected) {
                return true;
            }
        }

        return false;
    }

    private function canonical(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->value();
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
