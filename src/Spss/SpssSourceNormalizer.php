<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

/** Normalizes supported php-spss reader objects and array-based engine fixtures. */
final class SpssSourceNormalizer
{
    /**
     * @param array{variables: array<int, mixed>, data: array<int, mixed>, header?: mixed, documents?: array<int, mixed>} $source
     * @return array{variables: list<array{name: string, type: string, width: int, formatFamily: int, formatWidth: int, formatDecimals: int, label: ?string}>, data: array<int, mixed>, fileLabel: ?string, documents: list<string>}
     */
    public static function normalize(array $source): array
    {
        $variables = [];
        foreach ($source['variables'] as $variable) {
            $name = self::field($variable, 'name');
            if (!is_string($name) || $name === '') {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source variable must have a non-empty name.');
            }

            $width = self::intField($variable, 'width', 0);
            $type = self::field($variable, 'type');
            $print = self::field($variable, 'print');
            $formatFamily = is_array($print) && is_int($print[1] ?? null) ? $print[1] : self::intField($variable, 'format', $width > 0 ? 1 : 5);
            $formatWidth = is_array($print) && is_int($print[2] ?? null) ? $print[2] : ($width > 0 ? $width : 8);
            $formatDecimals = is_array($print) && is_int($print[3] ?? null) ? $print[3] : self::intField($variable, 'decimals', 0);
            $label = self::field($variable, 'label');

            $variables[] = [
                'name' => $name,
                'type' => is_string($type) ? $type : ($width > 0 ? 'string' : 'numeric'),
                'width' => $width,
                'formatFamily' => $formatFamily,
                'formatWidth' => $formatWidth,
                'formatDecimals' => $formatDecimals,
                'label' => is_string($label) ? $label : null,
            ];
        }

        $header = array_key_exists('header', $source) ? $source['header'] : null;
        $documents = $source['documents'] ?? [];

        return [
            'variables' => $variables,
            'data' => $source['data'],
            'fileLabel' => self::stringField($header, 'fileLabel'),
            'documents' => self::documents($documents),
        ];

    }
    private static function field(mixed $source, string $name): mixed
    {
        if (is_array($source)) {
            return $source[$name] ?? null;
        }
        if (is_object($source) && isset($source->{$name})) {
            return $source->{$name};
        }

        return null;
    }

    private static function intField(mixed $source, string $name, int $default): int
    {
        $value = self::field($source, $name);

        return is_int($value) ? $value : $default;
    }
    private static function stringField(mixed $source, string $name): ?string
    {
        $value = self::field($source, $name);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int, mixed> $documents
     * @return list<string>
     */
    private static function documents(array $documents): array
    {
        $result = [];
        foreach ($documents as $document) {
            if (is_string($document)) {
                $result[] = $document;
            }
        }
        return $result;
    }
}
