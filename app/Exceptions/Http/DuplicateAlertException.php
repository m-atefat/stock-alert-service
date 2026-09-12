<?php

namespace App\Exceptions\Http;

class DuplicateAlertException extends ApiException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(409, ErrorCode::DUPLICATE_ALERT, $message ?? __('exceptions.duplicate_alert'));
    }
}
