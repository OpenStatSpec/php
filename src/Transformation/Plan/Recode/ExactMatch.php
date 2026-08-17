<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

use OpenStatSpec\Transformation\Plan\Value\TypedValue;

final readonly class ExactMatch implements RecodeMatch
{
    /** @var non-empty-list<TypedValue> */
    public array $values;

    public function __construct(TypedValue ...$values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('A values match requires at least one value.');
        }

        $this->values = array_values($values);
    }

    /** @return array{kind: string, values: list<array<string, string>>} */
    public function canonicalArray(): array
    {
        return [
            'kind' => 'values',
            'values' => array_map(static fn(TypedValue $value): array => $value->canonicalArray(), $this->values),
        ];
    }
}
