<?php

namespace App\Services\Prices;

use App\Contracts\PriceProvider;
use App\Services\PriceProviders\GoldApi\GoldApiPriceProvider;
use Illuminate\Support\Manager;

final class PriceProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) config('prices.provider');
    }

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): PriceProvider
    {
        /** @var PriceProvider */
        return parent::driver($driver);
    }

    protected function createGoldapiDriver(): PriceProvider
    {
        return new GoldApiPriceProvider(
            (string) config('services.goldapi.base_url'),
            (string) config('services.goldapi.key'),
            (int) config('services.goldapi.timeout'),
            config('services.goldapi.proxy.enabled') ? (string) config('services.goldapi.proxy.url') : null,
        );
    }
}
