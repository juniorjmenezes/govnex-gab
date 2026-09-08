<?php

namespace App\Services\Politics\Polls;

use App\Models\PesquisaEleitoral;

/**
 * Uma fonte capaz de buscar o resultado (percentual por candidato) de uma
 * pesquisa eleitoral já conhecida. Cada fonte oficial tem seu próprio
 * adapter — nunca um scraper genérico por trás de vários institutos.
 *
 * Implementações não devem inventar ou inferir percentuais ausentes: se não
 * encontrar (ou não tiver certeza de) um resultado, devolva null em
 * buscar() em vez de um ResultadoColeta parcial ou estimado.
 */
interface PesquisaResultProvider
{
    /**
     * Indica se este provider é capaz de tentar buscar resultados para essa
     * pesquisa (cargo/abrangência suportados, dados mínimos presentes etc.) —
     * não garante que vai encontrar algo, só que faz sentido tentar.
     */
    public function supports(PesquisaEleitoral $pesquisa): bool;

    /**
     * Busca o resultado da pesquisa nesta fonte. Retorna null quando não
     * encontrar nada correspondente (não é erro — é ausência de dado).
     */
    public function buscar(PesquisaEleitoral $pesquisa): ?ResultadoColeta;
}
