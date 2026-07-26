<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOStatement;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

/** Reconstructs the strict-wide portion of a V3 Dataset from PostgreSQL. */
final readonly class PostgreSqlWideTableExporter
{
    public function __construct(private PDO $pdo) {}

    /** @return array{dataset: Dataset, caseCount: int, diagnostics: list<FidelityDiagnostic>} */
    public function export(string $datasetName, string $targetFormat = 'sav'): array
    {
        if (!in_array($targetFormat, ['sav', 'zsav'], true)) {
            throw new UnsupportedOperation(DiagnosticCode::UnsupportedSourceFormat, 'The PostgreSQL profile can only construct SAV or ZSAV datasets.');
        }

        $dataset = $this->dataset($datasetName);
        $variables = $this->variables($datasetName);
        $columns = array_map(fn(array $variable): string => $this->quote($variable['column_name']), $variables);
        $cases = $this->statement('SELECT ' . implode(', ', $columns) . ' FROM ' . $this->quote($dataset['table_name']) . ' ORDER BY "__case_ordinal"');
        $cases->execute();
        $rows = [];
        while (($row = $cases->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($variables as $variable) {
                $values[] = $this->caseValue($row[$variable['column_name']] ?? null, $variable['storage_kind']);
            }
            $rows[] = $values;
        }

        $typedVariables = [];
        foreach ($variables as $variable) {
            $isString = $variable['storage_kind'] === 'string';
            $typedVariables[] = new VariableMetadata(
                name: $variable['source_name'],
                type: $isString ? VariableType::STRING : VariableType::NUMERIC,
                width: $isString ? $variable['source_width'] : 0,
                printFormat: new VariableFormat($variable['format_family'], $variable['format_width'], $variable['format_decimals']),
                writeFormat: new VariableFormat($variable['format_family'], $variable['format_width'], $variable['format_decimals']),
                label: $variable['label'],
                dictionaryIndex: $variable['ordinal'],
            );
        }

        return [
            'dataset' => new Dataset(
                new VariableDictionary($typedVariables),
                $rows,
                new FileMetadata(),
                new FileTechnicalMetadata(sourceFormat: $targetFormat, compression: $targetFormat === 'zsav' ? 2 : 1),
            ),
            'caseCount' => count($rows),
            'diagnostics' => [new FidelityDiagnostic(
                'postgresql_dictionary_metadata_deferred',
                'PostgreSQL export currently preserves strict-wide case values and core variable definitions only. Value labels, user-missing rules, display settings, and file-level metadata require the next catalogue-export slice.',
            )],
        ];
    }

    /** @return array{table_name: string} */
    private function dataset(string $datasetName): array
    {
        $statement = $this->statement('SELECT table_name FROM datasets WHERE dataset_name = ?');
        $statement->execute([$datasetName]);
        $dataset = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dataset) || !is_string($dataset['table_name'] ?? null) || $dataset['table_name'] === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The requested dataset is not present in the PostgreSQL catalogue.');
        }

        return ['table_name' => $dataset['table_name']];
    }

    /** @return list<array{ordinal:int,source_name:string,column_name:string,storage_kind:'numeric'|'string',source_width:int,format_family:int,format_width:int,format_decimals:int,label:?string}> */
    private function variables(string $datasetName): array
    {
        $statement = $this->statement('SELECT ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, label FROM variables WHERE dataset_name = ? ORDER BY ordinal');
        $statement->execute([$datasetName]);
        $records = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($records === []) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The requested dataset has no variable catalogue entries.');
        }

        $variables = [];
        foreach ($records as $record) {
            $ordinal = $this->integer($record['ordinal'] ?? null);
            $sourceWidth = $this->integer($record['source_width'] ?? null);
            $formatFamily = $this->integer($record['format_family'] ?? null);
            $formatWidth = $this->integer($record['format_width'] ?? null);
            $formatDecimals = $this->integer($record['format_decimals'] ?? null);
            $sourceName = $record['source_name'] ?? null;
            $columnName = $record['column_name'] ?? null;
            $storageKind = $record['storage_kind'] ?? null;
            $label = $record['label'] ?? null;
            if ($ordinal === null || !is_string($sourceName) || $sourceName === '' || !is_string($columnName) || $columnName === '' || !in_array($storageKind, ['numeric', 'string'], true) || $sourceWidth === null || $formatFamily === null || $formatWidth === null || $formatDecimals === null || (!is_string($label) && $label !== null)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL variable catalogue is malformed.');
            }
            if ($storageKind === 'string' && ($sourceWidth < 1 || $sourceWidth > 32767)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL string variable catalogue contains an invalid storage width.');
            }
            $variables[] = [
                'ordinal' => $ordinal,
                'source_name' => $sourceName,
                'column_name' => $columnName,
                'storage_kind' => $storageKind,
                'source_width' => $sourceWidth,
                'format_family' => $formatFamily,
                'format_width' => $formatWidth,
                'format_decimals' => $formatDecimals,
                'label' => $label,
            ];
        }

        return $variables;
    }

    private function caseValue(mixed $value, string $storageKind): int|float|string|null
    {
        if ($storageKind === 'string') {
            if (!is_string($value)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL wide table contains a null or non-string SPSS string value.');
            }

            return $value;
        }
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL wide table contains a non-numeric SPSS numeric value.');
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL profile could not prepare a required catalogue query.');
        }

        return $statement;
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
