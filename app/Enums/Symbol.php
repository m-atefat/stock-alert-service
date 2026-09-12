<?php

namespace App\Enums;

use App\Concerns\EnumToArray;

enum Symbol: string
{
    use EnumToArray;

    case XauUsd = 'XAUUSD';

    public function metal(): string
    {
        return substr($this->value, 0, 3);
    }

    public function currency(): string
    {
        return substr($this->value, 3, 3);
    }

    public function label(): string
    {
        return $this->metal().'/'.$this->currency();
    }
}
