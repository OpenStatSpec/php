<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Plan\Value;

use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use PHPUnit\Framework\TestCase;

final class Binary64ValueTest extends TestCase
{
    public function testFromBitsRejectsNonFiniteValues(): void
    {
        $this->expectException(\OpenStatSpec\Transformation\Diagnostic\TransformationFailure::class);
        Binary64Value::fromBits('7ff8000000000000');
    }

    public function testFromBitsRejectsNegativeZero(): void
    {
        $this->expectException(\OpenStatSpec\Transformation\Diagnostic\TransformationFailure::class);
        Binary64Value::fromBits('8000000000000000');
    }

    public function testFromBitsRejectsInvalidLength(): void
    {
        $this->expectException(\OpenStatSpec\Transformation\Diagnostic\TransformationFailure::class);
        Binary64Value::fromBits('3ff000000000000');
    }

    public function testFromBitsRejectsUppercaseHex(): void
    {
        $this->expectException(\OpenStatSpec\Transformation\Diagnostic\TransformationFailure::class);
        Binary64Value::fromBits('3FF0000000000000');
    }

    public function testNumberReturnsCanonicalFloatForOne(): void
    {
        self::assertSame(1.0, Binary64Value::fromBits('3ff0000000000000')->number());
    }

    public function testCanonicalArrayReturnsBinary64Shape(): void
    {
        self::assertSame(
            ['type' => 'binary64', 'bits' => '3ff0000000000000'],
            Binary64Value::fromBits('3ff0000000000000')->canonicalArray(),
        );
    }

    public function testCanonicalKeyIsStableAndUnambiguous(): void
    {
        self::assertSame(
            'binary64:3ff0000000000000',
            Binary64Value::fromBits('3ff0000000000000')->canonicalKey(),
        );
    }

    public function testDecimalAlwaysUsesDotSeparator(): void
    {
        // The decimal() output must always round-trip back through PHP's float
        // parser; if localeconv() were to leak a comma separator, the string
        // would parse as zero and silently lose precision. Use 1.1 (binary64
        // approximation that always expands with a fractional part).
        $onePointOne = Binary64Value::fromBits('3ff199999999999a')->decimal();
        self::assertStringContainsString('.', $onePointOne);
        self::assertStringNotContainsString(',', $onePointOne);
        $negativeOnePointOne = Binary64Value::fromBits('bff199999999999a')->decimal();
        self::assertStringContainsString('.', $negativeOnePointOne);
        self::assertStringNotContainsString(',', $negativeOnePointOne);
    }

    public function testDecimalNormalizesLocaleWithCommaSeparator(): void
    {
        $original = setlocale(LC_NUMERIC, '0');
        $candidateLocales = ['de_DE.utf8', 'fr_FR.utf8', 'nl_NL.utf8', 'de_DE.UTF-8', 'fr_FR.UTF-8', 'nl_NL.UTF-8'];
        $restored = false;
        $commaLocale = null;
        foreach ($candidateLocales as $locale) {
            if (setlocale(LC_NUMERIC, $locale) !== false) {
                $commaLocale = $locale;
                break;
            }
        }
        try {
            if ($commaLocale === null) {
                self::markTestSkipped('No locale with a comma decimal separator is available on this system.');
            }
            $separator = localeconv()['decimal_point'] ?? '.';
            self::assertSame(',', $separator, 'The selected locale must actually use a comma decimal separator.');
            $decimal = Binary64Value::fromBits('4024000000000000')->decimal();
            self::assertStringContainsString('.', $decimal);
            self::assertStringNotContainsString(',', $decimal);
        } finally {
            if ($original !== false) {
                setlocale(LC_NUMERIC, $original);
                $restored = true;
            } elseif ($commaLocale !== null) {
                setlocale(LC_NUMERIC, 'C');
            }
            self::assertTrue($restored || $commaLocale !== null);
        }
    }
}
