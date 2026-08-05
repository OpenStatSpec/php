<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

/** Declares and adds one variable to the existing dataset. */
final readonly class CreateVariableOperation implements TransformationOperation
{
    public const MAX_STRING_WIDTH = 32767;
    public function __construct(
        private string $variable,
        private string $storageKind,
        private ?int $declaredStringWidth = null,
    ) {
        if (!in_array($storageKind, ['numeric', 'string'], true)) {
            throw new \InvalidArgumentException('storageKind must be numeric or string.');
        }
        if ($storageKind === 'string' && ($declaredStringWidth === null || $declaredStringWidth < 1)) {
            throw new \InvalidArgumentException('String variables require a positive declared string width.');
        }
        if ($storageKind === 'string' && $declaredStringWidth > self::MAX_STRING_WIDTH) {
            throw new \InvalidArgumentException(
                'String variables support at most ' . self::MAX_STRING_WIDTH . ' bytes.',
            );
        }
        if ($storageKind === 'numeric' && $declaredStringWidth !== null) {
            throw new \InvalidArgumentException('Numeric variables cannot declare a string width.');
        }
    }

    public function type(): string
    {
        return 'create_variable';
    }
    public function sourceVariable(): string
    {
        return $this->variable;
    }
    public function targetVariable(): string
    {
        return $this->variable;
    }
    public function storageKind(): string
    {
        return $this->storageKind;
    }
    public function declaredStringWidth(): ?int
    {
        return $this->declaredStringWidth;
    }

    public function canonicalArray(): array
    {
        return array_filter([
            'type' => $this->type(),
            'source_variable' => $this->variable,
            'target_variable' => $this->variable,
            'storage_kind' => $this->storageKind,
            'declared_string_width' => $this->declaredStringWidth,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
