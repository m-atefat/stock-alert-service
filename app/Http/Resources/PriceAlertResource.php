<?php

namespace App\Http\Resources;

use App\Models\PriceAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PriceAlert $resource
 */
class PriceAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'symbol' => $this->resource->symbol->value,
            'target_price' => $this->resource->target_price,
            'direction' => $this->resource->direction->label(),
            'status' => $this->resource->status->label(),
            'triggered_price' => $this->resource->triggered_price,
            'triggered_at' => $this->resource->triggered_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
