<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $alert_id
 * @property NotificationStatus $status
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceAlert $alert
 */
#[Fillable(['alert_id', 'status', 'attempts', 'sent_at', 'error'])]
class AlertNotification extends Model
{
    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PriceAlert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(PriceAlert::class, 'alert_id');
    }
}
