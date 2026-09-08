<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cor de representação de um partido nos gráficos e pesquisas do painel
 * político — definida uma única vez pelo root e aplicada a todos os
 * gabinetes, em vez de cada um escolher a própria cor por partido.
 *
 * @property int $id
 * @property string $sigla
 * @property string $cor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PartidoCor extends Model
{
    protected $table = 'partido_cores';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (PartidoCor $partidoCor): void {
            $partidoCor->sigla = Str::upper(trim($partidoCor->sigla));
            $partidoCor->cor = Str::upper(trim($partidoCor->cor));
        });
    }

    /**
     * Sigla => cor hexadecimal, com a sigla normalizada (sem acento,
     * maiúscula) — o mesmo partido aparece com e sem acento a depender da
     * fonte (ex.: "MISSÃO" cadastrado aqui vs. "MISSAO" vindo do TSE), então
     * a busca precisa ignorar essa diferença em vez de exigir grafia
     * idêntica.
     *
     * @return array<string, string>
     */
    public static function colorMap(): array
    {
        return static::query()
            ->get(['sigla', 'cor'])
            ->mapWithKeys(fn (PartidoCor $partidoCor): array => [
                self::normalizeSigla($partidoCor->sigla) => $partidoCor->cor,
            ])
            ->all();
    }

    public static function normalizeSigla(string $sigla): string
    {
        return Str::of($sigla)->trim()->ascii()->upper()->toString();
    }
}
