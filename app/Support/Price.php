<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

final readonly class Price implements Stringable
{
    private const int SCALE = 8;

    private const string MAX_EXACT_SCALED = '9007199254740992';

    public string $value;

    public function __construct(string $value)
    {
        if (bccomp($value, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException("Price must be a positive decimal string, got [{$value}].");
        }

        $this->value = bcadd($value, '0', self::SCALE);
    }

    public function isGreaterThanOrEqualTo(self|string $other): bool
    {
        return bccomp($this->value, self::normalize($other), self::SCALE) >= 0;
    }

    public function isLessThanOrEqualTo(self|string $other): bool
    {
        return bccomp($this->value, self::normalize($other), self::SCALE) <= 0;
    }

    public function equals(self|string $other): bool
    {
        return bccomp($this->value, self::normalize($other), self::SCALE) === 0;
    }

    public function scaledScore(): string
    {
        $scaled = bcmul($this->value, '100000000', 0);

        if (bccomp($scaled, self::MAX_EXACT_SCALED, 0) > 0) {
            throw new InvalidArgumentException(
                "Price [{$this->value}] exceeds the exactly-representable ZSET score range "
                .'(2^53 after 1e8 scaling). Reduce SCALE or store scores as a scaled bigint.'
            );
        }

        return $scaled;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function normalize(self|string $other): string
    {
        return $other instanceof self ? $other->value : $other;
    }
}
