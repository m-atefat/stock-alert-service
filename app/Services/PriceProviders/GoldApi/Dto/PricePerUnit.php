<?php

namespace App\Services\PriceProviders\GoldApi\Dto;

final readonly class PricePerUnit
{
    public function __construct(
        public string $troyOunce,
        public string $gram,
        public string $kilogram,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            troyOunce: (string) $data['troy_ounce'],
            gram: (string) $data['gram'],
            kilogram: (string) $data['kilogram'],
        );
    }
}
