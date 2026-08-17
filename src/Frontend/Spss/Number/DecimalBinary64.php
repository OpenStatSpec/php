<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Number;

use Brick\Math\BigInteger;
use OpenStatSpec\Frontend\Spss\Diagnostic;
use OpenStatSpec\Frontend\Spss\SpssSyntaxException;

final class DecimalBinary64
{
    private const FINITE = '/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D';

    public static function bits(string $token): string
    {
        if (preg_match(self::FINITE, $token, $matches) !== 1) {
            self::reject('Numeric literal must use the finite SPSS decimal grammar.');
        }

        $negative = $matches[1] === '-';
        $fraction = $matches[3] ?? '';
        $coefficient = BigInteger::of($matches[2] . $fraction);
        $decimalExponent = BigInteger::of($matches[4] ?? '0')->minus(strlen($fraction));

        if ($coefficient->isZero()) {
            return '0000000000000000';
        }

        $adjustedDecimalExponent = $decimalExponent->plus(strlen($coefficient->toString()));
        if ($adjustedDecimalExponent->isGreaterThan(309)) {
            self::reject('Numeric literal exceeds the finite IEEE-754 binary64 range.');
        }
        if ($adjustedDecimalExponent->isLessThanOrEqualTo(-324)) {
            return '0000000000000000';
        }

        $power = $decimalExponent->toInt();
        if ($power >= 0) {
            $numerator = $coefficient->multipliedBy(BigInteger::ten()->power($power));
            $denominator = BigInteger::one();
        } else {
            $numerator = $coefficient;
            $denominator = BigInteger::ten()->power(-$power);
        }

        $exponent = self::floorLog2($numerator, $denominator);
        if ($exponent > 1023) {
            self::reject('Numeric literal exceeds the finite IEEE-754 binary64 range.');
        }

        if ($exponent < -1022) {
            $significand = self::roundRatio($numerator->shiftedLeft(1074), $denominator);
            if ($significand->isZero()) {
                return '0000000000000000';
            }

            $word = $significand;
        } else {
            $shift = 52 - $exponent;
            $significand = $shift >= 0
                ? self::roundRatio($numerator->shiftedLeft($shift), $denominator)
                : self::roundRatio($numerator, $denominator->shiftedLeft(-$shift));

            $hiddenBit = BigInteger::one()->shiftedLeft(52);
            if ($significand->isEqualTo($hiddenBit->shiftedLeft(1))) {
                $significand = $hiddenBit;
                ++$exponent;
                if ($exponent > 1023) {
                    self::reject('Numeric literal exceeds the finite IEEE-754 binary64 range.');
                }
            }

            $word = BigInteger::of($exponent + 1023)
                ->shiftedLeft(52)
                ->plus($significand->minus($hiddenBit));
        }

        if ($negative) {
            $word = $word->plus(BigInteger::one()->shiftedLeft(63));
        }

        return str_pad($word->toBase(16), 16, '0', STR_PAD_LEFT);
    }

    private static function floorLog2(BigInteger $numerator, BigInteger $denominator): int
    {
        $exponent = $numerator->getBitLength() - $denominator->getBitLength();
        $comparison = $exponent >= 0
            ? $numerator->compareTo($denominator->shiftedLeft($exponent))
            : $numerator->shiftedLeft(-$exponent)->compareTo($denominator);

        return $comparison < 0 ? $exponent - 1 : $exponent;
    }

    private static function roundRatio(BigInteger $numerator, BigInteger $denominator): BigInteger
    {
        [$quotient, $remainder] = $numerator->quotientAndRemainder($denominator);
        $twice = $remainder->multipliedBy(2);
        $comparison = $twice->compareTo($denominator);
        if ($comparison > 0 || ($comparison === 0 && $quotient->isOdd())) {
            return $quotient->plus(1);
        }

        return $quotient;
    }

    private static function reject(string $message): never
    {
        throw new SpssSyntaxException([new Diagnostic(1, 1, $message)]);
    }
}
