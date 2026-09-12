<?php

namespace App\Enums;

use App\Concerns\EnumToArray;
use App\Concerns\HasLabel;

/**
 * An alert has exactly two states. Deletion is a hard delete (see
 * PriceAlertController::destroy), and a failed *delivery* is recorded on the
 * notification row, not here — so there is no Cancelled or Failed case.
 */
enum AlertStatus: int
{
    use EnumToArray, HasLabel;

    case Active = 1;
    case Triggered = 2;
}
