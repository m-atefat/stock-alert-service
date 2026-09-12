<?php

namespace App\Enums;

use App\Concerns\EnumToArray;
use App\Concerns\HasLabel;

enum AlertDirection: int
{
    use EnumToArray, HasLabel;

    case Above = 1;
    case Below = 2;
}
