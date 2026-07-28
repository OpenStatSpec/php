<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Spss\SpssMissingValueSentinel;
use PHPUnit\Framework\TestCase;

final class SpssMissingValueSentinelTest extends TestCase
{
    public function testRecognizesExactSpssRangeSentinels(): void
    {
        self::assertTrue(SpssMissingValueSentinel::isLowest(SpssMissingValueSentinel::lowest()));
        self::assertTrue(SpssMissingValueSentinel::isHighest(SpssMissingValueSentinel::highest()));
    }

    public function testLargeFiniteBinary64ValuesAreNotSentinels(): void
    {
        self::assertFalse(SpssMissingValueSentinel::isLowest(-1.5e308));
        self::assertFalse(SpssMissingValueSentinel::isHighest(1.5e308));
        self::assertFalse(SpssMissingValueSentinel::isLowest(-PHP_FLOAT_MAX));
    }
}
