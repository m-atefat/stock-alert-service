<?php

namespace App\Concerns;

trait EnumToArray
{
    /**
     * @return array<string, int|string>
     */
    public static function toArray(): array
    {
        return array_column(self::cases(), 'value', 'name');
    }

    /**
     * @return array<int, int|string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
