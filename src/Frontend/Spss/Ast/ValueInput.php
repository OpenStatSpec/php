<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class ValueInput implements RecodeInput
{
    /** @var non-empty-list<ScalarValue> */
    public array $values;
    public ScalarValue $value;

    public function __construct(ScalarValue ...$values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('A RECODE values selector requires at least one value.');
        }

        $this->values = array_values($values);
        $this->value = $values[0];
    }
}
