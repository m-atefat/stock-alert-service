<?php

namespace App\Exceptions\Http;

class PriceUnavailableException extends ApiException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(503, ErrorCode::PRICE_UNAVAILABLE, $message ?? __('exceptions.price_unavailable'));
    }
}
