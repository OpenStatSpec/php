<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use SPSS\Sav\Dataset;
use SPSS\Sav\MissingValues;
use SPSS\Sav\MissingValuesKind;

/** Converts php-spss V3 Dataset objects to the adapter's strict SQL source model. */
final class SpssSourceNormalizer
{
    /** @return array<string, mixed> */
    public static function normalize(Dataset $source): array
    {
        $variables = [];
        $valueLabels = [];
        foreach ($source->variables() as $index => $variable) {
            if ($variable->name === '') {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source variable must have a non-empty name.');
            }

            $variables[] = [
                'name' => $variable->name,
                'type' => $variable->type->value,
                'width' => $variable->width,
                'formatFamily' => $variable->printFormat->code,
                'formatWidth' => $variable->printFormat->width,
                'formatDecimals' => $variable->printFormat->decimals,
                'label' => $variable->label,
                'missingFormat' => self::missingFormat($variable->missingValues),
                'missingValues' => self::missingValues($variable->missingValues),
            ];

            $labels = [];
            foreach ($variable->valueLabels->labels() as $label) {
                $labels[] = ['value' => $label->value, 'label' => $label->label];
            }
            if ($labels !== []) {
                $valueLabels[] = ['indexes' => [$index], 'labels' => $labels];
            }
        }

        return [
            'variables' => $variables,
            'data' => $source->rows(),
            'fileLabel' => $source->metadata->label,
            'documents' => $source->metadata->documents(),
            'valueLabels' => $valueLabels,
            'displayParameters' => self::displayParameters($source),
        ];
    }

    private static function missingFormat(MissingValues $missingValues): int
    {
        return match ($missingValues->kind) {
            MissingValuesKind::NONE => 0,
            MissingValuesKind::DISCRETE => count($missingValues->discreteValues()),
            MissingValuesKind::RANGE => -2,
            MissingValuesKind::RANGE_AND_VALUE => -3,
        };
    }

    /** @return list<int|float|string> */
    private static function missingValues(MissingValues $missingValues): array
    {
        return match ($missingValues->kind) {
            MissingValuesKind::NONE => [],
            MissingValuesKind::DISCRETE => $missingValues->discreteValues(),
            MissingValuesKind::RANGE => [self::numeric($missingValues->lower), self::numeric($missingValues->upper)],
            MissingValuesKind::RANGE_AND_VALUE => [self::numeric($missingValues->lower), self::numeric($missingValues->upper), self::numeric($missingValues->additionalValue)],
        };
    }

    private static function numeric(int|float|null $value): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A numeric missing-value rule is incomplete.');
        }

        return $value;
    }

    /** @return list<array{measure: int, columns: int, alignment: int}> */
    private static function displayParameters(Dataset $source): array
    {
        $result = [];
        foreach ($source->variables() as $variable) {
            $result[] = [
                'measure' => $variable->measure->value,
                'columns' => $variable->columns,
                'alignment' => $variable->alignment->value,
            ];
        }

        return $result;
    }
}
