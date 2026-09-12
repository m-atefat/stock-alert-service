<?php

namespace App\Models;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\Symbol;
use Database\Factories\PriceAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Symbol $symbol
 * @property string $target_price
 * @property AlertDirection $direction
 * @property AlertStatus $status
 * @property string|null $triggered_price
 * @property Carbon|null $triggered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read AlertNotification|null $notification
 */
#[Fillable(['user_id', 'symbol', 'target_price', 'direction', 'status', 'triggered_price', 'triggered_at'])]
class PriceAlert extends Model
{
    /** @use HasFactory<PriceAlertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'symbol' => Symbol::class,
            'target_price' => 'decimal:8',
            'triggered_price' => 'decimal:8',
            'triggered_at' => 'datetime',
            'direction' => AlertDirection::class,
            'status' => AlertStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<AlertNotification, $this>
     */
    public function notification(): HasOne
    {
        return $this->hasOne(AlertNotification::class, 'alert_id');
    }

    /**
     * @param  Builder<PriceAlert>  $query
     * @return Builder<PriceAlert>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AlertStatus::Active);
    }
}
