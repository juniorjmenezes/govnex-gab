<?php

namespace App\Services\Hub;

use Exception;

/**
 * A API do Govnex Hub não respondeu ao resolver estrutura sob demanda.
 *
 * Não estende `RuntimeException` de propósito: o webhook trata
 * `RuntimeException` como aviso inaplicável (409, que o Hub não repete) e
 * qualquer outra falha como temporária (500, que o Hub reenvia). Hub fora do
 * ar é temporário.
 */
class HubIndisponivelException extends Exception {}
