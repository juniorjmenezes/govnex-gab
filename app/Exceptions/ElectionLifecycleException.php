<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pedido recusado no ciclo de vida de uma eleição (sincronizar um dataset,
 * cancelar uma execução, apurar). A mensagem é para o usuário: o controller
 * a mostra como está. `$field` indica quando a recusa é de validação de um
 * campo do pedido (ex.: dataset fora do tipo da eleição) e não de estado.
 */
class ElectionLifecycleException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
