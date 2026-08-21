<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Diagnostic;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use PHPUnit\Framework\TestCase;

final class SourceSpanTest extends TestCase
{
    public function testCanonicalConstructorAcceptsOrderedOffsets(): void
    {
        $span = new SourceSpan(10, 20, 1, 1, 1, 11);
        self::assertSame(10, $span->startOffset);
        self::assertSame(20, $span->endOffset);
    }

    public function testConstructorRejectsInvertedOffsets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start offset must not exceed its end offset');
        new SourceSpan(20, 10);
    }

    public function testConstructorRejectsInvertedLineColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start position must not exceed its end position');
        new SourceSpan(0, 10, 2, 1, 1, 1);
    }

    public function testConstructorRejectsInvertedColumnOnSameLine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SourceSpan(0, 10, 1, 10, 1, 1);
    }

    public function testCoverAcceptsProperlyOrderedSpans(): void
    {
        $start = new SourceSpan(10, 15, 1, 1, 1, 6);
        $end = new SourceSpan(20, 25, 1, 11, 1, 16);
        $covered = SourceSpan::cover($start, $end);
        self::assertSame(10, $covered->startOffset);
        self::assertSame(25, $covered->endOffset);
        self::assertSame(1, $covered->startLine);
        self::assertSame(1, $covered->startColumn);
        self::assertSame(1, $covered->endLine);
        self::assertSame(16, $covered->endColumn);
    }

    public function testCoverRejectsEndSpanBeforeStartSpan(): void
    {
        $start = new SourceSpan(20, 25, 2, 1, 2, 6);
        $end = new SourceSpan(10, 15, 1, 1, 1, 6);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cover() requires the end span');
        SourceSpan::cover($start, $end);
    }
}
