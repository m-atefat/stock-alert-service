<?php

namespace App\Services\PriceProviders\GoldApi\Dto;

final readonly class MeltPricePerGram
{
    public function __construct(
        public string $k24,
        public string $k22,
        public string $k18,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            k24: (string) $data['24k'],
            k22: (string) $data['22k'],
            k18: (string) $data['18k'],
        );
    }
}
