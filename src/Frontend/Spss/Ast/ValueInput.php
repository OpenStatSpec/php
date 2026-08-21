<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ValueInput implements RecodeInput
{
    /** @var non-empty-list<ScalarValue> */
    public array $values;
    public ScalarValue $value;
    public SourceSpan $span;

    public function __construct(ScalarValue ...$values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('A RECODE values selector requires at least one value.');
        }

        $this->values = array_values($values);
        $this->value = $values[0];
        $this->span = SourceSpan::cover($values[0]->span, $values[array_key_last($values)]->span);
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
