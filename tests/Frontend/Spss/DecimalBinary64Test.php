<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use Brick\Math\BigInteger;
use OpenStatSpec\Frontend\Spss\Number\DecimalBinary64;
use OpenStatSpec\Frontend\Spss\SpssSyntaxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalBinary64Test extends TestCase
{
    #[DataProvider('tokens')]
    public function testExactBits(string $token, string $bits): void
    {
        self::assertSame($bits, DecimalBinary64::bits($token));
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokens(): iterable
    {
        yield 'negative zero' => ['-0', '0000000000000000'];
        yield 'underflowed zero' => ['-1e-9999', '0000000000000000'];
        yield 'minimum subnormal' => ['4.9406564584124654417656879286822137236505980e-324', '0000000000000001'];
        yield 'one' => ['1', '3ff0000000000000'];
        yield 'negative one' => ['-1', 'bff0000000000000'];
        yield 'tie rounds even' => ['1.00000000000000011102230246251565404236316680908203125', '3ff0000000000000'];
        yield 'tie rounds odd upward' => ['1.00000000000000033306690738754696212708950042724609375', '3ff0000000000002'];
        yield 'significand carry' => ['1.99999999999999988897769753748434595763683319091796875', '4000000000000000'];
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidOrOverflowingTokens(string $token): void
    {
        $this->expectException(SpssSyntaxException::class);

        DecimalBinary64::bits($token);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'leading plus' => ['+1'];
        yield 'missing integer part' => ['.5'];
        yield 'missing fractional part' => ['1.'];
        yield 'leading zero' => ['01'];
        yield 'NaN' => ['NaN'];
        yield 'infinity' => ['Infinity'];
        yield 'overflow' => ['1e9999'];
    }

    public function testNormalSubnormalMidpointRoundsToEvenMinimumNormal(): void
    {
        $midpoint = BigInteger::of(2)->power(53)
            ->minus(1)
            ->multipliedBy(BigInteger::of(5)->power(1075));

        self::assertSame('0010000000000000', DecimalBinary64::bits(self::fraction($midpoint, 1075)));
    }

    public function testValueBelowOverflowMidpointRoundsToMaximumFinite(): void
    {
        $midpoint = BigInteger::of(2)->power(54)
            ->minus(1)
            ->multipliedBy(BigInteger::of(2)->power(970));

        self::assertSame('7fefffffffffffff', DecimalBinary64::bits($midpoint->minus(1)->toString()));
    }

    public function testOverflowMidpointIsRejected(): void
    {
        $midpoint = BigInteger::of(2)->power(54)
            ->minus(1)
            ->multipliedBy(BigInteger::of(2)->power(970));
        $this->expectException(SpssSyntaxException::class);

        DecimalBinary64::bits($midpoint->toString());
    }

    private static function fraction(BigInteger $numerator, int $decimalPlaces): string
    {
        return '0.' . str_pad($numerator->toString(), $decimalPlaces, '0', STR_PAD_LEFT);
    }
}
