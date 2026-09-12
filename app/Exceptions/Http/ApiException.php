<?php

namespace App\Exceptions\Http;

use RuntimeException;

abstract class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
