<?php

namespace App\Contracts;

use App\Enums\Symbol;
use App\Exceptions\PriceProviderException;
use App\Support\PriceQuote;

interface PriceProvider
{
    /**
     * @throws PriceProviderException on any fetch failure.
     */
    public function fetch(Symbol $symbol): PriceQuote;
}
