<?php

namespace App\Services\PriceProviders\GoldApi\Dto;

final readonly class CurrencyInfo
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) $data['code'],
            name: (string) $data['name'],
            symbol: (string) $data['symbol'],
        );
    }
}
