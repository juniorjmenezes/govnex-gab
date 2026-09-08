<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly bool $ambiguous = false,
    ) {
        parent::__construct($message);
    }
}
