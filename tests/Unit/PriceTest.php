<?php

namespace Tests\Unit;

use App\Support\Price;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PriceTest extends TestCase
{
    #[Test]
    public function scaled_score_succeeds_for_a_value_at_the_exact_double_precision_boundary(): void
    {
        $price = new Price('90071992.54740992');

        $this->assertSame('9007199254740992', $price->scaledScore());
    }

    #[Test]
    public function scaled_score_succeeds_for_a_value_just_under_the_boundary(): void
    {
        $price = new Price('90071992.54740991');

        $this->assertSame('9007199254740991', $price->scaledScore());
    }

    #[Test]
    public function scaled_score_throws_for_a_value_just_over_the_boundary(): void
    {
        $price = new Price('90071992.54740993');

        $this->expectException(InvalidArgumentException::class);

        $price->scaledScore();
    }
}
