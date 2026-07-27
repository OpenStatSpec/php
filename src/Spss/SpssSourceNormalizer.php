<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use SPSS\Sav\Dataset;
use SPSS\Sav\MissingValues;
use SPSS\Sav\MissingValuesKind;
use SPSS\Sav\VariableType;

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
            if (
                $variable->type === VariableType::STRING
                && in_array($variable->missingValues->kind, [MissingValuesKind::RANGE, MissingValuesKind::RANGE_AND_VALUE], true)
            ) {
                throw new UnsupportedOperation(
                    DiagnosticCode::InvalidSourceDataset,
                    'SPSS string variables may have discrete user-missing values only; missing-value ranges are numeric only.',
                );
            }

            $variables[] = [
                'name' => $variable->name,
                'type' => $variable->type->value,
                'width' => $variable->width,
                // The legacy format* keys are the SPSS print format. Write
                // format is separate and must never be inferred from it.
                'formatFamily' => $variable->printFormat->code,
                'formatWidth' => $variable->printFormat->width,
                'formatDecimals' => $variable->printFormat->decimals,
                'writeFormatFamily' => $variable->writeFormat->code,
                'writeFormatWidth' => $variable->writeFormat->width,
                'writeFormatDecimals' => $variable->writeFormat->decimals,
                'label' => $variable->label,
                'missingFormat' => self::missingFormat($variable->missingValues),
                'missingValues' => self::missingValues($variable->missingValues),
                'role' => $variable->role->value,
                'attributes' => array_map(
                    static fn($attribute): array => ['name' => $attribute->name, 'values' => $attribute->values()],
                    $variable->attributes(),
                ),
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
            'weightVariableName' => $source->metadata->weightVariableName,
            'documents' => $source->metadata->documents(),
            'valueLabels' => $valueLabels,
            'displayParameters' => self::displayParameters($source),
            'fileAttributes' => array_map(
                static fn($attribute): array => ['name' => $attribute->name, 'values' => $attribute->values()],
                $source->metadata->attributes(),
            ),
            'variableSets' => array_map(
                static fn($set): array => ['name' => $set->name, 'variableNames' => $set->variableNames()],
                $source->metadata->variableSets(),
            ),
            'multipleResponseSets' => array_map(
                static fn($set): array => [
                    'name' => $set->name,
                    'type' => $set->type->value,
                    'variableNames' => $set->variableNames(),
                    'label' => $set->label,
                    'countedValue' => $set->countedValue,
                    'categoryLabels' => $set->categoryLabels->value,
                    'labelSource' => $set->labelSource->value,
                ],
                $source->metadata->multipleResponseSets(),
            ),
            'technicalMetadata' => self::technicalMetadata($source),
        ];
    }

    /** @return array<string, int|float|string|null> */
    private static function technicalMetadata(Dataset $source): array
    {
        $technical = $source->technicalMetadata;

        return [
            'sourceFormat' => $technical->sourceFormat,
            'recordType' => $technical->recordType,
            'sourceVersion' => $technical->sourceVersion,
            'provenance' => $technical->provenance,
            'encoding' => $technical->encoding,
            'productName' => $technical->productName,
            'rawCreationDate' => $technical->rawCreationDate,
            'rawCreationTime' => $technical->rawCreationTime,
            'caseCount' => $technical->caseCount,
            'nominalCaseSize' => $technical->nominalCaseSize,
            'layoutCode' => $technical->layoutCode,
            'compression' => $technical->compression,
            'compressionBias' => $technical->compressionBias,
            'machineCode' => $technical->machineCode,
            'floatingPointRepresentation' => $technical->floatingPointRepresentation,
            'endianness' => $technical->endianness,
            'characterCode' => $technical->characterCode,
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
