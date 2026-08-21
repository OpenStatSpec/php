<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Plan;

use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Recode\CopyResult;
use OpenStatSpec\Transformation\Plan\Recode\ExactMatch;
use OpenStatSpec\Transformation\Plan\Recode\LiteralResult;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\TargetMode;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use PHPUnit\Framework\TestCase;

final class RecodeOperationTest extends TestCase
{
    public function testEmptyRulesAreRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one rule');
        $operation = new RecodeOperation('source', 'target', TargetMode::Replace, [], new CopyResult());
        self::assertInstanceOf(RecodeOperation::class, $operation);
    }

    public function testValidRuleListRemainsCanonicalizable(): void
    {
        $operation = new RecodeOperation(
            'source',
            'target',
            TargetMode::Create,
            [new RecodeRule(
                new ExactMatch(Binary64Value::fromBits('3ff0000000000000')),
                new LiteralResult(Binary64Value::fromBits('4000000000000000')),
            )],
            new CopyResult(),
        );
        self::assertCount(1, $operation->canonicalArray()['rules']);
    }
}
