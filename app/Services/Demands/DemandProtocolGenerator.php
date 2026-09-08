<?php

namespace App\Services\Demands;

use Illuminate\Support\Facades\DB;

class DemandProtocolGenerator
{
    public function next(int $gabineteId, int $year, string $format = '{ANO}-{SEQUENCIAL}'): string
    {
        return DB::transaction(function () use ($gabineteId, $year, $format): string {
            DB::table('demanda_protocol_sequences')->insertOrIgnore([
                'gabinete_id' => $gabineteId,
                'ano' => $year,
                'proximo_numero' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('demanda_protocol_sequences')
                ->where('gabinete_id', $gabineteId)
                ->where('ano', $year)
                ->lockForUpdate()
                ->first();

            $number = (int) $sequence->proximo_numero;

            DB::table('demanda_protocol_sequences')
                ->where('gabinete_id', $gabineteId)
                ->where('ano', $year)
                ->update([
                    'proximo_numero' => $number + 1,
                    'updated_at' => now(),
                ]);

            return str_replace(
                ['{ANO}', '{SEQUENCIAL}'],
                [(string) $year, str_pad((string) $number, 6, '0', STR_PAD_LEFT)],
                $format,
            );
        }, 3);
    }
}
