<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

/**
 * Narrow boundary around the externally installed TonisOrmisson/php-spss engine.
 *
 * This package deliberately does not declare a VCS-only Composer dependency.
 */
final class PhpSpssEngine
{
    public const PACKAGE = 'tiamo/spss';
    public const READER_CLASS = 'SPSS\\Sav\\Reader';

    public function isAvailable(): bool
    {
        return class_exists(self::READER_CLASS);
    }

    /**
     * @return array{header: mixed, variables: array<int, mixed>, valueLabels: array<int, mixed>, documents: array<int, mixed>, info: array<int, mixed>, data: array<int, mixed>}
     */
    public function read(string $sourcePath): array
    {
        if (!$this->isAvailable()) {
            throw new UnsupportedOperation(
                DiagnosticCode::ExternalEngineUnavailable,
                'The selected SPSS engine is not installed. Install a compatible TonisOrmisson/php-spss checkout before importing SAV data.',
            );
        }

        $readerClass = self::READER_CLASS;
        $reader = $readerClass::fromFile($sourcePath)->read();

        return [
            'header' => $reader->header,
            'variables' => $reader->variables,
            'valueLabels' => $reader->valueLabels,
            'documents' => $reader->documents,
            'info' => $reader->info,
            'data' => $reader->data,
        ];
    }

}
