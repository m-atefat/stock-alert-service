<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\Symbol;
use App\Exceptions\Http\DuplicateAlertException;
use App\Exceptions\Http\PriceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePriceAlertRequest;
use App\Http\Resources\PriceAlertResource;
use App\Redis\AlertIndex;
use App\Support\Price;
use App\Support\PriceCache;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

class PriceAlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PriceAlertResource::collection(
            $request->user()->priceAlerts()->latest()->paginate(20),
        );
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function store(StorePriceAlertRequest $request, AlertIndex $index, PriceCache $priceCache): JsonResponse
    {
        $symbol = Symbol::XauUsd;
        $cached = $priceCache->get($symbol);

        if ($cached === null) {
            throw new PriceUnavailableException;
        }

        $targetPrice = new Price((string) $request->target_price);
        $currentPrice = new Price((string) $cached['price']);

        $direction = $targetPrice->isGreaterThanOrEqualTo($currentPrice)
            ? AlertDirection::Above
            : AlertDirection::Below;

        try {
            $alert = DB::transaction(fn () => $request->user()->priceAlerts()->create([
                'symbol' => $symbol,
                'target_price' => (string) $targetPrice,
                'direction' => $direction,
                'status' => AlertStatus::Active,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new DuplicateAlertException;
            }

            throw $e;
        }

        $index->add($alert->symbol, $alert->direction, $alert->id, (string) $alert->target_price);

        return response()->json(PriceAlertResource::make($alert), 201);
    }

    public function destroy(Request $request, int $alertId, AlertIndex $index): Response
    {
        $alert = $request->user()->priceAlerts()->findOrFail($alertId);

        $alert->delete();
        $index->remove($alert->symbol, $alert->direction, $alert->id);

        return response()->noContent();
    }
}
