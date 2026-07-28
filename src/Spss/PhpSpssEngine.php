<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use SPSS\Sav\Dataset;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableMetadata;

/** Typed boundary around the Composer-installed php-spss V3 engine. */
final class PhpSpssEngine implements SpssEngine
{
    public const PACKAGE = 'tiamo/spss';
    public const READER_CLASS = 'SPSS\\Sav\\Reader';
    public const WRITER_CLASS = 'SPSS\\Sav\\Writer';
    public const CLAIMED_VERSION_RANGE = '>=3.0.0 <4.0.0';
    public const CI_TESTED_VERSIONS = ['3.0.0'];

    public function isAvailable(): bool
    {
        return class_exists(self::READER_CLASS);
    }

    public function identity(): array
    {
        $version = class_exists(\Composer\InstalledVersions::class)
            ? \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null;

        return [
            'package' => self::PACKAGE,
            'version' => $version,
            'active_version' => $version,
            'claimed_version_range' => self::CLAIMED_VERSION_RANGE,
            'ci_tested_versions' => self::CI_TESTED_VERSIONS,
            'claimed_supported' => self::isClaimedVersionSupported($version),
        ];
    }

    public static function isClaimedVersionSupported(?string $version): bool
    {
        return is_string($version) && preg_match('/^v?3\.\d+\.\d+(?:[-+].*)?$/', $version) === 1;
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return array_fill_keys([
            'sav_read',
            'sav_write',
            'zsav_read',
            'zsav_write',
            'file_label',
            'documents',
            'source_encoding',
            'attributes',
            'variable_dictionary',
            'value_labels',
            'missing_rules',
            'lowest_highest_missing',
            'long_utf8_strings',
            'weight_variable',
            'variable_sets',
            'multiple_response_sets',
            'multiple_response_string_counted_value',
        ], true);
    }

    public function read(string $sourcePath): Dataset
    {
        if (!$this->isAvailable()) {
            throw new UnsupportedOperation(
                DiagnosticCode::ExternalEngineUnavailable,
                'The selected SPSS engine is not installed. Install Composer dependencies including tiamo/spss before importing SAV data.',
            );
        }

        $readerClass = self::READER_CLASS;

        return $this->withoutReservedRoleAttribute($readerClass::fromFile($sourcePath)->readDataset());
    }

    public function write(string $targetPath, Dataset $dataset): void
    {
        if (!class_exists(self::WRITER_CLASS)) {
            throw new UnsupportedOperation(
                DiagnosticCode::ExternalEngineUnavailable,
                'The selected SPSS engine is not installed. Install Composer dependencies including tiamo/spss before exporting SAV data.',
            );
        }

        $writerClass = self::WRITER_CLASS;
        $writer = $writerClass::createInFile($targetPath, $dataset);
        $writer->close();
    }

    /**
     * php-spss V3 stores VariableRole in the SAV dictionary as the reserved
     * "$@Role" attribute, but also exposes it in VariableMetadata::$role.
     * OpenStatSpec keeps the role in its dedicated metadata relation, so the
     * serialization detail must never be presented as a custom attribute.
     */
    private function withoutReservedRoleAttribute(Dataset $dataset): Dataset
    {
        $variables = [];
        $changed = false;

        foreach ($dataset->variables() as $variable) {
            $attributes = array_values(array_filter(
                $variable->attributes(),
                static fn($attribute): bool => '$@Role' !== $attribute->name,
            ));
            $changed = $changed || count($attributes) !== count($variable->attributes());

            $variables[] = new VariableMetadata(
                name: $variable->name,
                type: $variable->type,
                width: $variable->width,
                printFormat: $variable->printFormat,
                writeFormat: $variable->writeFormat,
                shortName: $variable->shortName,
                label: $variable->label,
                valueLabels: $variable->valueLabels,
                missingValues: $variable->missingValues,
                measure: $variable->measure,
                alignment: $variable->alignment,
                columns: $variable->columns,
                role: $variable->role,
                attributes: $attributes,
                dictionaryIndex: $variable->dictionaryIndex,
            );
        }

        if (!$changed) {
            return $dataset;
        }

        return new Dataset(
            dictionary: new VariableDictionary($variables),
            rows: $dataset->rows(),
            metadata: $dataset->metadata,
            technicalMetadata: $dataset->technicalMetadata,
        );
    }
}
