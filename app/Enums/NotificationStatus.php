<?php

namespace App\Enums;

use App\Concerns\EnumToArray;

enum NotificationStatus: int
{
    use EnumToArray;

    case Pending = 1;
    case Sent = 2;
    case Failed = 3;
    case Sending = 4;
}
