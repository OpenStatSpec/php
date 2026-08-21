<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Request;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final readonly class InputVariable
{
    /** @param list<array{value: array<string, string>, label: string}> $valueLabels */
    public function __construct(
        public string $name,
        public string $storageKind,
        public ?string $variableLabel = null,
        public array $valueLabels = [],
        public ?string $formatFamily = null,
        public ?int $width = null,
        public ?int $decimals = null,
        public ?string $measurementLevel = null,
    ) {
        self::nonEmptyString($name, '$.input_schema.variables[].name');
        if (!in_array($storageKind, ['numeric', 'string'], true)) {
            self::schema('$.input_schema.variables[].storage_kind', 'Storage kind must be numeric or string.');
        }
        self::nullableString($variableLabel, '$.input_schema.variables[].variable_label');
        self::nullableString($formatFamily, '$.input_schema.variables[].format_family');
        if ($width !== null && $width < 1) {
            self::schema('$.input_schema.variables[].width', 'Width must be at least one.');
        }
        if ($decimals !== null && $decimals < 0) {
            self::schema('$.input_schema.variables[].decimals', 'Decimals must not be negative.');
        }
        if (!in_array($measurementLevel, [null, 'nominal', 'ordinal', 'scale'], true)) {
            self::schema('$.input_schema.variables[].measurement_level', 'Invalid measurement level.');
        }
        self::valueLabels($valueLabels, '$.input_schema.variables[].value_labels');
    }

    public static function fromArray(mixed $raw, string $path): self
    {
        if (!is_array($raw) || array_is_list($raw)) {
            self::schema($path, 'Input variable must be an object.');
        }
        $allowed = [
            'name',
            'storage_kind',
            'variable_label',
            'value_labels',
            'format_family',
            'width',
            'decimals',
            'measurement_level',
        ];
        if (!array_key_exists('name', $raw) || !array_key_exists('storage_kind', $raw)) {
            self::schema($path, 'Input variable is missing a required member.');
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                self::schema($path, 'Input variable has an extra member.');
            }
        }

        $name = self::nonEmptyString($raw['name'], $path . '.name');
        $storageKind = self::string($raw['storage_kind'], $path . '.storage_kind');
        if (!in_array($storageKind, ['numeric', 'string'], true)) {
            self::schema($path . '.storage_kind', 'Storage kind must be numeric or string.');
        }
        $variableLabel = self::optionalNullableString($raw, 'variable_label', $path);
        $formatFamily = self::optionalNullableString($raw, 'format_family', $path);
        $width = self::optionalNullableInteger($raw, 'width', $path, 1);
        $decimals = self::optionalNullableInteger($raw, 'decimals', $path, 0);
        $measurementLevel = self::optionalNullableString($raw, 'measurement_level', $path);
        if (!in_array($measurementLevel, [null, 'nominal', 'ordinal', 'scale'], true)) {
            self::schema($path . '.measurement_level', 'Invalid measurement level.');
        }

        return new self(
            $name,
            $storageKind,
            $variableLabel,
            self::valueLabels($raw['value_labels'] ?? [], $path . '.value_labels'),
            $formatFamily,
            $width,
            $decimals,
            $measurementLevel,
        );
    }

    /** @return list<array{value: array<string, string>, label: string}> */
    private static function valueLabels(mixed $raw, string $path): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            self::schema($path, 'Value labels must be an ordered array.');
        }
        $labels = [];
        foreach ($raw as $index => $label) {
            $labelPath = $path . '[' . $index . ']';
            if (!is_array($label) || array_is_list($label)) {
                self::schema($labelPath, 'Value label must be an object.');
            }
            $keys = array_keys($label);
            sort($keys, SORT_STRING);
            if ($keys !== ['label', 'value']) {
                self::schema($labelPath, 'Value label has missing or extra members.');
            }
            $labels[] = [
                'value' => self::typedValue($label['value'], $labelPath . '.value'),
                'label' => self::string($label['label'], $labelPath . '.label'),
            ];
        }

        return $labels;
    }

    /** @return array<string, string> */
    private static function typedValue(mixed $raw, string $path): array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            self::schema($path, 'Typed value must be an object.');
        }
        $type = self::string($raw['type'] ?? null, $path . '.type');
        if ($type === 'binary64') {
            self::exactKeys($raw, ['type', 'bits'], $path);
            $bits = self::string($raw['bits'], $path . '.bits');
            if (
                preg_match('/\A[0-9a-f]{16}\z/D', $bits) !== 1
                || $bits === '8000000000000000'
                || substr($bits, 0, 3) === '7ff'
                || substr($bits, 0, 3) === 'fff'
            ) {
                self::schema($path . '.bits', 'Invalid canonical binary64 bits.');
            }

            return ['type' => 'binary64', 'bits' => $bits];
        }
        if ($type === 'string') {
            self::exactKeys($raw, ['type', 'value'], $path);

            return ['type' => 'string', 'value' => self::string($raw['value'], $path . '.value')];
        }

        self::schema($path . '.type', 'Typed value type must be binary64 or string.');
    }

    /** @param array<string, mixed> $raw */
    private static function optionalNullableString(array $raw, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        return self::string($raw[$key], $path . '.' . $key);
    }

    /** @param array<string, mixed> $raw */
    private static function optionalNullableInteger(array $raw, string $key, string $path, int $minimum): ?int
    {
        if (!array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }
        if (!is_int($raw[$key]) || $raw[$key] < $minimum) {
            self::schema($path . '.' . $key, 'Value is outside the allowed integer range.');
        }

        return $raw[$key];
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $expected
     */
    private static function exactKeys(array $object, array $expected, string $path): void
    {
        $keys = array_keys($object);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
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

    private static function nullableString(?string $value, string $path): void
    {
        if ($value !== null && preg_match('//u', $value) !== 1) {
            self::schema($path, 'Value must be a valid UTF-8 string.');
        }
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
