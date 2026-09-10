<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Quantidade de registros por página das listagens. O seletor da barra de
 * paginação oferece só estes valores; qualquer outro cai no padrão da
 * própria listagem, que varia com o formato (tabela, cartão, grade) e por
 * isso continua sendo decidido em cada controller.
 */
class PerPage
{
    /** @var list<int> */
    public const OPTIONS = [15, 30, 50];

    public static function resolve(Request $request, int $default): int
    {
        $requested = $request->integer('per_page');

        return in_array($requested, self::OPTIONS, true) ? $requested : $default;
    }
}
