<?php

namespace App\Exceptions\Http;

class InvalidCredentialsHttpException extends ApiException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(401, ErrorCode::INVALID_CREDENTIALS, $message ?? __('exceptions.invalid_credentials'));
    }
}
