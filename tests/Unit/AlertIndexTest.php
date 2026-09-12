<?php

namespace Tests\Unit;

use App\Enums\Symbol;
use App\Redis\AlertIndex;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AlertIndexTest extends TestCase
{
    #[Test]
    public function a_non_noscript_script_error_throws_instead_of_returning_an_empty_match(): void
    {
        $client = Mockery::mock();
        $client->shouldReceive('getLastError')->andReturn('ERR too many results to unpack');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('script')->once()->with('load', Mockery::type('string'))->andReturn('deadbeef');
        $connection->shouldReceive('command')->once()->with('evalsha', Mockery::type('array'))->andReturn(false);
        $connection->shouldReceive('client')->andReturn($client);

        $index = new AlertIndex($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too many results to unpack');

        $index->matchAbove(Symbol::XauUsd, '2000.00000000', 1000);
    }

    #[Test]
    public function noscript_reloads_the_script_and_retries_once(): void
    {
        $client = Mockery::mock();
        $client->shouldReceive('getLastError')->andReturn('NOSCRIPT No matching script.');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('script')->twice()->with('load', Mockery::type('string'))->andReturn('deadbeef');
        $connection->shouldReceive('command')->once()->with('evalsha', Mockery::type('array'))->andReturn(false);
        $connection->shouldReceive('command')->once()->with('evalsha', Mockery::type('array'))->andReturn([1, 2, 3]);
        $connection->shouldReceive('client')->andReturn($client);

        $index = new AlertIndex($connection);

        $this->assertSame([1, 2, 3], $index->matchAbove(Symbol::XauUsd, '2000.00000000', 1000));
    }
}
