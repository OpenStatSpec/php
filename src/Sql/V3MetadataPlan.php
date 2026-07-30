<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

/** Canonical, mutation-free validation result for V3 SPSS metadata. */
final readonly class V3MetadataPlan
{
    /**
     * @param list<int> $roles
     * @param list<array{variableOrdinal: int, name: string, values: list<string>}> $variableAttributes
     * @param list<array{name: string, values: list<string>}> $fileAttributes
     * @param list<array{name: string, members: list<int>}> $variableSets
     * @param list<array{name: string, type: string, label: ?string, countedValue: int|float|string|null, categoryLabels: string, labelSource: string, members: list<int>}> $multipleResponseSets
     */
    private function __construct(
        public array $roles,
        public array $variableAttributes,
        public array $fileAttributes,
        public array $variableSets,
        public array $multipleResponseSets,
    ) {}

    /** @param array<string, mixed> $source */
    public static function fromSourceIfPresent(array $source): ?self
    {
        $present = array_key_exists('fileAttributes', $source)
            || array_key_exists('variableSets', $source)
            || array_key_exists('multipleResponseSets', $source);
        $variables = $source['variables'] ?? null;
        if (is_array($variables)) {
            foreach ($variables as $variable) {
                if (is_array($variable) && (array_key_exists('role', $variable) || array_key_exists('attributes', $variable))) {
                    $present = true;
                    break;
                }
            }
        }

        return $present ? self::fromSource($source) : null;
    }

    /** @param array<string, mixed> $source */
    public static function fromSource(array $source): self
    {
        $variables = self::list($source['variables'] ?? null, 'V3 source variables');
        if ($variables === []) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'V3 metadata requires a source variable list.');
        }

        $roles = [];
        $variableAttributes = [];
        $variableOrdinals = [];
        foreach ($variables as $ordinal => $variable) {
            $name = self::field($variable, 'name');
            if (!is_string($name) || $name === '' || isset($variableOrdinals[$name])) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Source variable names must be unique for metadata references.');
            }
            $variableOrdinals[$name] = $ordinal + 1;

            $role = self::field($variable, 'role');
            if (!is_int($role) || $role < 0 || $role > 5) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every variable must have a valid SPSS role.');
            }
            $roles[] = $role;

            foreach (self::attributes(self::field($variable, 'attributes') ?? [], 'Variable') as $attribute) {
                $variableAttributes[] = [
                    'variableOrdinal' => $ordinal + 1,
                    'name' => $attribute['name'],
                    'values' => $attribute['values'],
                ];
            }
        }

        return new self(
            $roles,
            $variableAttributes,
            self::attributes($source['fileAttributes'] ?? [], 'File'),
            self::variableSets($source['variableSets'] ?? [], $variableOrdinals),
            self::multipleResponseSets($source['multipleResponseSets'] ?? [], $variableOrdinals),
        );
    }

    /**
     * @param array<string, int> $variableOrdinals
     * @return list<array{name: string, members: list<int>}>
     */
    private static function variableSets(mixed $sourceSets, array $variableOrdinals): array
    {
        $result = [];
        $names = [];
        foreach (self::list($sourceSets, 'Variable sets') as $source) {
            $name = self::field($source, 'name');
            $members = self::field($source, 'variableNames');
            if (!is_string($name) || $name === '' || isset($names[$name]) || !is_array($members) || !array_is_list($members)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every variable set must have a unique name and ordered members.');
            }
            $names[$name] = true;
            $result[] = ['name' => $name, 'members' => self::members($members, $variableOrdinals, 'A variable set')];
        }

        return $result;
    }

    /**
     * @param array<string, int> $variableOrdinals
     * @return list<array{name: string, type: string, label: ?string, countedValue: int|float|string|null, categoryLabels: string, labelSource: string, members: list<int>}>
     */
    private static function multipleResponseSets(mixed $sourceSets, array $variableOrdinals): array
    {
        $result = [];
        $names = [];
        foreach (self::list($sourceSets, 'Multiple-response sets') as $source) {
            $name = self::field($source, 'name');
            $type = self::field($source, 'type');
            $members = self::field($source, 'variableNames');
            $label = self::field($source, 'label');
            $countedValue = self::field($source, 'countedValue');
            $categoryLabels = self::field($source, 'categoryLabels');
            $labelSource = self::field($source, 'labelSource');
            if (!is_string($name) || $name === '' || isset($names[$name])
                || !in_array($type, ['category', 'dichotomy'], true)
                || !is_array($members) || !array_is_list($members)
                || ($label !== null && !is_string($label))
                || !in_array($categoryLabels, ['variable_labels', 'counted_values'], true)
                || !in_array($labelSource, ['set_label', 'variable_label'], true)
                || ($countedValue !== null && !is_int($countedValue) && !is_float($countedValue) && !is_string($countedValue))
            ) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A multiple-response set is malformed.');
            }
            if ((is_int($countedValue) || is_float($countedValue)) && !is_finite((float) $countedValue)) {
                throw new UnsupportedOperation(DiagnosticCode::TargetCapabilityExceeded, 'SQL targets reject non-finite multiple-response counted values before mutation.');
            }
            if (($type === 'category' && $countedValue !== null) || ($type === 'dichotomy' && ($countedValue === null || $countedValue === ''))) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A multiple-response set has an invalid counted value.');
            }
            $names[$name] = true;
            $result[] = [
                'name' => $name,
                'type' => $type,
                'label' => $label,
                'countedValue' => $countedValue,
                'categoryLabels' => $categoryLabels,
                'labelSource' => $labelSource,
                'members' => self::members($members, $variableOrdinals, 'A multiple-response set'),
            ];
        }

        return $result;
    }

    /**
     * @param list<mixed> $sourceMembers
     * @param array<string, int> $variableOrdinals
     * @return list<int>
     */
    private static function members(array $sourceMembers, array $variableOrdinals, string $description): array
    {
        $members = [];
        $seen = [];
        foreach ($sourceMembers as $variableName) {
            if (!is_string($variableName) || !isset($variableOrdinals[$variableName])) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' references an unknown source variable.');
            }
            if (isset($seen[$variableName])) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' must not contain duplicate source variables.');
            }
            $seen[$variableName] = true;
            $members[] = $variableOrdinals[$variableName];
        }

        return $members;
    }

    /** @return list<array{name: string, values: list<string>}> */
    private static function attributes(mixed $sourceAttributes, string $description): array
    {
        $result = [];
        $names = [];
        foreach (self::list($sourceAttributes, $description . ' attributes') as $attribute) {
            $name = self::field($attribute, 'name');
            $values = self::field($attribute, 'values');
            if (!is_string($name) || $name === '' || isset($names[$name]) || !is_array($values) || !array_is_list($values) || $values === []) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every ' . strtolower($description) . ' attribute must have a unique name and ordered values.');
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' attribute values must be strings.');
                }
            }
            $names[$name] = true;
            $result[] = ['name' => $name, 'values' => $values];
        }

        return $result;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $description): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' must be a list.');
        }

        return $value;
    }

    private static function field(mixed $source, string $name): mixed
    {
        return is_array($source) ? ($source[$name] ?? null) : null;
    }
}
