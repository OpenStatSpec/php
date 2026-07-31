<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

/** A source-language-neutral mutation within one existing dataset. */
interface TransformationOperation
{
    public function type(): string;

    public function sourceVariable(): string;

    public function targetVariable(): string;

    /** @return array<string, mixed> */
    public function canonicalArray(): array;
}
