<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Request;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final readonly class SpssFrontendRequest
{
    public const CONTRACT = 'openstatspec-spss-syntax-frontend-v0.2';
    public const CONTRACT_V03 = 'openstatspec-spss-syntax-frontend-v0.3';

    public function __construct(
        public string $contract,
        public string $inputAlias,
        public InputSchema $inputSchema,
        public string $sourceText,
    ) {
        if (!in_array($contract, [self::CONTRACT, self::CONTRACT_V03], true)) {
            self::schema('$.contract', 'Unsupported SPSS frontend contract.');
        }
        self::nonEmptyString($inputAlias, '$.input_alias');
        self::nonEmptyString($sourceText, '$.source_text');
    }

    /** @param array<string, mixed> $request */
    public static function fromArray(array $request): self
    {
        self::exactKeys($request, ['contract', 'input_alias', 'input_schema', 'source_text'], '$');
        $contract = self::string($request['contract'], '$.contract');
        $inputAlias = self::nonEmptyString($request['input_alias'], '$.input_alias');
        $sourceText = self::nonEmptyString($request['source_text'], '$.source_text');

        return new self(
            $contract,
            $inputAlias,
            InputSchema::fromArray($request['input_schema'], '$.input_schema'),
            $sourceText,
        );
    }

    public function sourceHash(): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $this->sourceText));
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $expected
     */
    private static function exactKeys(array $object, array $expected, string $path): void
    {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            self::schema($path, 'Object has missing or extra members.');
        }
    }

    private static function nonEmptyString(mixed $value, string $path): string
    {
        $string = self::string($value, $path);
        if ($string === '') {
            self::schema($path, 'String must not be empty.');
        }

        return $string;
    }

    private static function string(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            self::schema($path, 'Value must be a valid UTF-8 string.');
        }

        return $value;
    }

    private static function schema(string $path, string $message): never
    {
        throw TransformationFailure::at('plan_schema_invalid', $path, $message);
    }
}
