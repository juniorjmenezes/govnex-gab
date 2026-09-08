<?php

namespace App\Actions\Demands;

use App\Models\Demanda;
use App\Models\User;

/**
 * Favoritar é um destaque do time inteiro (como em `CandidatoFavorito`), não
 * uma preferência pessoal — por isso vive como duas colunas simples na
 * própria demanda, sem tabela própria. Não gera evento de timeline: é
 * metadado de organização da caixa de entrada, não um acontecimento da
 * demanda.
 */
class ToggleDemandFavorite
{
    public function handle(Demanda $demand, User $user): Demanda
    {
        $demand->forceFill(
            $demand->isFavoritada()
                ? ['favoritada_em' => null, 'favoritada_por_id' => null]
                : ['favoritada_em' => now(), 'favoritada_por_id' => $user->id],
        )->save();

        return $demand;
    }
}
