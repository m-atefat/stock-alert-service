<?php

namespace App\Support;

use App\Enums\Symbol;
use Carbon\CarbonImmutable;

final readonly class PriceQuote
{
    public function __construct(
        public Symbol $symbol,
        public string $price,
        public CarbonImmutable $quotedAt,
    ) {}
}
