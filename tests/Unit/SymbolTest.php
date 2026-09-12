<?php

namespace Tests\Unit;

use App\Enums\Symbol;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SymbolTest extends TestCase
{
    #[Test]
    public function metal_and_currency_split_the_symbol(): void
    {
        $this->assertSame('XAU', Symbol::XauUsd->metal());
        $this->assertSame('USD', Symbol::XauUsd->currency());
    }
}
