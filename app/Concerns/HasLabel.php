<?php

namespace App\Concerns;

trait HasLabel
{
    public function label(): string
    {
        return strtolower($this->name);
    }
}
