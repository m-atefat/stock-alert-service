<?php

namespace Tests\Feature;

use App\Services\PriceProviders\GoldApi\GoldApiPriceProvider;
use App\Services\Prices\PriceProviderManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

class PriceProviderManagerTest extends FeatureTestCase
{
    #[Test]
    public function the_default_driver_resolves_to_the_goldapi_provider(): void
    {
        $driver = app(PriceProviderManager::class)->driver();

        $this->assertInstanceOf(GoldApiPriceProvider::class, $driver);
    }

    #[Test]
    public function an_unknown_driver_name_fails_loudly_instead_of_being_swallowed(): void
    {
        config(['prices.provider' => 'nope']);

        $this->expectException(InvalidArgumentException::class);

        app(PriceProviderManager::class)->driver();
    }

    #[Test]
    public function the_manager_caches_the_resolved_driver(): void
    {
        $manager = app(PriceProviderManager::class);

        $this->assertSame($manager->driver(), $manager->driver());
    }
}
