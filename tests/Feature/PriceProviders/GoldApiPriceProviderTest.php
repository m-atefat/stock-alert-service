<?php

namespace Tests\Feature\PriceProviders;

use App\Exceptions\PriceProviderException;
use App\Services\PriceProviders\GoldApi\GoldApiPriceProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoldApiPriceProviderTest extends TestCase
{
    private const array SAMPLE_RESPONSE = [
        'timestamp' => 1777005765,
        'datetime' => '2026-04-24T04:42:45Z',
        'metal' => 'XAU',
        'currency' => 'USD',
        'exchange' => 'FOREXCOM',
        'symbol' => 'FOREXCOM:XAUUSD',
        'prev_close_price' => 4693.025,
        'open_price' => 4693.025,
        'low_price' => 4658.09,
        'high_price' => 4711.21,
        'open_time' => 1776988800,
        'price' => 4665.825,
        'unit' => 'troy_ounce',
        'change' => -27.2,
        'change_percent' => -0.58,
        'ask' => 4666.32,
        'bid' => 4665.36,
        'price_per_unit' => [
            'troy_ounce' => 4665.825,
            'gram' => 150.0098,
            'kilogram' => 150009.8,
        ],
        'melt_price_per_gram' => [
            '24k' => 150.0098,
            '22k' => 137.5089,
            '18k' => 112.5073,
        ],
        'currency_info' => [
            'code' => 'USD',
            'name' => 'United States Dollar',
            'symbol' => 'US$',
        ],
    ];

    private function provider(): GoldApiPriceProvider
    {
        return new GoldApiPriceProvider('https://www.goldapi.io/api', 'test-key');
    }

    #[Test]
    public function maps_the_full_response_into_dtos_at_every_level(): void
    {
        Http::fake([
            'www.goldapi.io/api/price/XAU/USD' => Http::response(self::SAMPLE_RESPONSE, 200),
        ]);

        $result = $this->provider()->getPrice('XAU', 'USD');

        $this->assertSame(1777005765, $result->timestamp);
        $this->assertSame('XAU', $result->metal);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('FOREXCOM', $result->exchange);
        $this->assertSame('4665.825', $result->price);
        $this->assertSame('-27.2', $result->change);

        $this->assertSame('4665.825', $result->pricePerUnit->troyOunce);
        $this->assertSame('150.0098', $result->pricePerUnit->gram);
        $this->assertSame('150009.8', $result->pricePerUnit->kilogram);

        $this->assertSame('150.0098', $result->meltPricePerGram->k24);
        $this->assertSame('137.5089', $result->meltPricePerGram->k22);
        $this->assertSame('112.5073', $result->meltPricePerGram->k18);

        $this->assertSame('USD', $result->currencyInfo->code);
        $this->assertSame('United States Dollar', $result->currencyInfo->name);
        $this->assertSame('US$', $result->currencyInfo->symbol);

        Http::assertSent(fn ($request) => $request->hasHeader('x-access-token', 'test-key'));
    }

    #[Test]
    public function throws_on_a_non_2xx_response(): void
    {
        Http::fake([
            'www.goldapi.io/api/price/XAU/USD' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        $this->expectException(PriceProviderException::class);

        $this->provider()->getPrice('XAU', 'USD');
    }

    #[Test]
    public function throws_on_a_malformed_payload(): void
    {
        Http::fake([
            'www.goldapi.io/api/price/XAU/USD' => Http::response(['unexpected' => true], 200),
        ]);

        $this->expectException(PriceProviderException::class);

        $this->provider()->getPrice('XAU', 'USD');
    }
}
