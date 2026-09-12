<?php

namespace App\Services\PriceProviders\GoldApi;

use App\Contracts\PriceProvider;
use App\Enums\Symbol;
use App\Exceptions\PriceProviderException;
use App\Services\PriceProviders\GoldApi\Dto\GoldPriceResponse;
use App\Support\PriceQuote;
use Illuminate\Support\Facades\Http;
use Throwable;

readonly class GoldApiPriceProvider implements PriceProvider
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 5,
        private ?string $proxy = null,
    ) {}

    public function fetch(Symbol $symbol): PriceQuote
    {
        $response = $this->getPrice($symbol->metal(), $symbol->currency());

        return new PriceQuote($symbol, $response->price, $response->datetime);
    }

    /**
     * @throws PriceProviderException on any HTTP failure, non-2xx status, or malformed payload.
     */
    public function getPrice(string $metal, string $currency = 'USD'): GoldPriceResponse
    {
        try {
            $request = Http::baseUrl($this->baseUrl)
                ->withHeaders(['x-access-token' => $this->apiKey])
                ->timeout($this->timeout);

            if ($this->proxy !== null) {
                $request = $request->withOptions(['proxy' => $this->proxy]);
            }

            $response = $request->get("/price/{$metal}/{$currency}");
        } catch (Throwable $e) {
            throw new PriceProviderException("GoldAPI.io request failed for [{$metal}/{$currency}]: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new PriceProviderException("GoldAPI.io returned HTTP {$response->status()} for [{$metal}/{$currency}].");
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['price'])) {
            throw new PriceProviderException("GoldAPI.io returned a malformed payload for [{$metal}/{$currency}].");
        }

        return GoldPriceResponse::fromArray($data);
    }
}
