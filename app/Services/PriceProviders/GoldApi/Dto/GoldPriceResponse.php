<?php

namespace App\Services\PriceProviders\GoldApi\Dto;

use Carbon\CarbonImmutable;

final readonly class GoldPriceResponse
{
    public function __construct(
        public int $timestamp,
        public CarbonImmutable $datetime,
        public string $metal,
        public string $currency,
        public string $exchange,
        public string $symbol,
        public string $prevClosePrice,
        public string $openPrice,
        public string $lowPrice,
        public string $highPrice,
        public int $openTime,
        public string $price,
        public string $unit,
        public string $change,
        public string $changePercent,
        public string $ask,
        public string $bid,
        public PricePerUnit $pricePerUnit,
        public MeltPricePerGram $meltPricePerGram,
        public CurrencyInfo $currencyInfo,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            timestamp: (int) $data['timestamp'],
            datetime: CarbonImmutable::parse($data['datetime']),
            metal: (string) $data['metal'],
            currency: (string) $data['currency'],
            exchange: (string) $data['exchange'],
            symbol: (string) $data['symbol'],
            prevClosePrice: (string) $data['prev_close_price'],
            openPrice: (string) $data['open_price'],
            lowPrice: (string) $data['low_price'],
            highPrice: (string) $data['high_price'],
            openTime: (int) $data['open_time'],
            price: (string) $data['price'],
            unit: (string) $data['unit'],
            change: (string) $data['change'],
            changePercent: (string) $data['change_percent'],
            ask: (string) $data['ask'],
            bid: (string) $data['bid'],
            pricePerUnit: PricePerUnit::fromArray($data['price_per_unit']),
            meltPricePerGram: MeltPricePerGram::fromArray($data['melt_price_per_gram']),
            currencyInfo: CurrencyInfo::fromArray($data['currency_info']),
        );
    }
}
