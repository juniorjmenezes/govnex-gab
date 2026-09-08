<?php

namespace App\Exceptions;

use RuntimeException;

class TseSyncCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A sincronização do TSE foi cancelada durante o processamento.');
    }
}
