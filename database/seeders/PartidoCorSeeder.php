<?php

namespace Database\Seeders;

use App\Models\PartidoCor;
use Illuminate\Database\Seeder;

/**
 * Cores oficiais de identidade visual dos partidos, definidas para adoção em
 * produção — não é dado de demonstração, por isso roda independente do
 * ambiente. Reaplicar o seeder inclui siglas novas sem sobrescrever cores
 * personalizadas pelo administrador.
 *
 * Uso isolado (ex.: em produção): `php artisan db:seed --class="Database\Seeders\PartidoCorSeeder"`.
 */
class PartidoCorSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->colors() as $sigla => $cor) {
            PartidoCor::query()->firstOrCreate(['sigla' => $sigla], ['cor' => $cor]);
        }
    }

    /** @return array<string, string> Sigla => cor hexadecimal. */
    private function colors(): array
    {
        return [
            'REPUBLICANOS' => '#005CA9',
            'PP' => '#54B8EA',
            'PDT' => '#005CA9',
            'PT' => '#E30613',
            'MISSÃO' => '#FCBE26',
            'MDB' => '#009959',
            'PSTU' => '#D3151E',
            'REDE' => '#099EC8',
            'PODE' => '#6B2FB3',
            'PCB' => '#FF0000',
            'PL' => '#30306C',
            'CIDADANIA' => '#EC008C',
            'PRD' => '#007C3C',
            'DC' => '#003399',
            'PRTB' => '#009846',
            'PCO' => '#CC0000',
            'NOVO' => '#FF6500',
            'MOBILIZA' => '#3D1345',
            'DEMOCRATA' => '#0F172A',
            'AGIR' => '#2670CA',
            'PSB' => '#FFB400',
            'PV' => '#016124',
            'UNIÃO' => '#00A4E8',
            'PSDB' => '#012BCA',
            'PSOL' => '#68018F',
            'PSD' => '#83C34E',
            'PCDOB' => '#ED1C24',
            'AVANTE' => '#67A4AD',
            'SOLIDARIEDADE' => '#F37021',
            'UP' => '#111111',
        ];
    }
}
